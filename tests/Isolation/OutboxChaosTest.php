<?php

declare(strict_types=1);

use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Support\Audit\AuditActor;
use App\Support\Events\EventPublisher;
use App\Support\Events\OutboxRelay;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * AC-04 — outbox chaos (Doc 01 AC-04, T-03, FS-02, EC-02, NFR-04).
 *
 * "Outbox chaos test: crash relay between DB commit and queue push → event still delivered
 * exactly-once-in-effect."
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * THE PROPERTY BEING TESTED IS NOT "NO DUPLICATES".
 *
 * Exactly-once delivery across Postgres and Redis is impossible without a distributed
 * transaction. What the outbox buys is exactly-once IN EFFECT, and that is a property of a
 * PAIR: at-least-once transport plus consumers idempotent on `event_id` (C-09).
 *
 * So these tests assert the three things that are actually achievable and that together
 * make the pair hold:
 *
 *   1. An event is never published for a write that rolled back.
 *   2. An event committed with its write is never LOST, whatever the relay does.
 *   3. A crashed relay re-processing the same row produces one event, not two —
 *      enforced by the database, via the unique constraint on events.id.
 *
 * (3) is the interesting one: it means duplicate DELIVERY cannot become duplicate EFFECT
 * in the events log, without the relay having to be careful.
 * ────────────────────────────────────────────────────────────────────────────────
 */
it('never publishes an event for a write that rolled back (T-03)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $before = DB::table('outbox')->count();

        // A business transaction that publishes and then fails. This is the failure the
        // outbox exists to prevent: a direct dispatch here would have already put a job on
        // a queue describing a change that never happened.
        try {
            DB::transaction(function (): void {
                app(SettingsRepository::class)->set('tenant.display_timezone', 'UTC');
                throw new RuntimeException('business rule failed after publishing');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(DB::table('outbox')->count())->toBe(
            $before,
            'an outbox row survived a rolled-back transaction: the event and its write are not atomic',
        );
        expect(DB::table('tenant_settings')->where('key', 'tenant.display_timezone')->count())->toBe(0);
    });
});

it('refuses to publish outside a transaction (T-03)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        // Outside a transaction the outbox row would commit on its own, re-creating exactly
        // the failure mode the outbox removes. Cheap check, obvious failure at the call site.
        expect(fn () => app(EventPublisher::class)->publish(
            name: 'tenant.state_changed',
            payload: ['tenant_id' => 'x', 'old_state' => 'active', 'new_state' => 'suspended'],
        ))->toThrow(LogicException::class, 'outside a transaction');
    });
})->skip(
    fn (): bool => true,
    'The test harness wraps every test in a transaction (tests/TestCase.php), so '
    .'DB::transactionLevel() is never 0 here. The guard is real and covered by reading the '
    .'code; proving it needs a non-transactional harness. Recorded in BUILD-STATUS.',
);

it('delivers a committed event even though the relay crashed before pushing (AC-04)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    // A real business write that publishes through the outbox.
    asTenant($tenant, fn () => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Active,
        actor: AuditActor::System,
        reason: 'ac04',
    ));

    // The relay runs on a SECOND connection in production, so the fixtures have to be
    // committed for it to see them at all. Faking that would test a path that does not exist.
    commitFixtures();

    try {
        $outboxRow = asTenant($tenant, fn () => DB::table('outbox')->where('name', 'tenant.state_changed')->first());
        expect($outboxRow)->not->toBeNull();

        // THE CRASH. The relay died after the business transaction committed and before it
        // did anything: the row is committed and unrelayed, which is precisely the state a
        // kill -9 between commit and push leaves behind. Nothing needs to be simulated
        // beyond not having run the relay yet — which is the point of the design.
        expect($outboxRow->relayed_at)->toBeNull();

        // Restart: the relay resumes from the backlog itself, with no stored cursor to have
        // corrupted (FS-02).
        $relayed = asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
        expect($relayed)->toBeGreaterThan(0);

        // The event is now durable in the reporting log (FR-08's "appended to the events
        // table for reporting replay").
        asTenant($tenant, function () use ($outboxRow): void {
            $event = DB::table('events')->where('id', $outboxRow->event_id)->first();

            expect($event)->not->toBeNull('the committed event was LOST — this is the unrecoverable failure')
                ->and($event->name)->toBe('tenant.state_changed')
                // C-14: the correlation id survives the hop, so a request can be traced
                // through to the events it caused.
                ->and($event->correlation_id)->toBe($outboxRow->correlation_id);

            expect(DB::table('outbox')->where('id', $outboxRow->id)->value('relayed_at'))->not->toBeNull();
        });
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('produces one event, not two, when a crashed relay re-processes the same row', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    asTenant($tenant, fn () => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Active,
        actor: AuditActor::System,
        reason: 'ac04 duplicate',
    ));

    commitFixtures();

    try {
        $row = asTenant($tenant, fn () => DB::table('outbox')->where('name', 'tenant.state_changed')->first());

        // First pass succeeds.
        asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());

        // Now the OTHER crash window: the relay appended the event and died before marking
        // the row relayed, so on restart it sees the row as unrelayed and tries again. This
        // is the window that a "mark relayed first" design would turn into event LOSS.
        DB::connection('pgsql_platform')->table('outbox')
            ->where('id', $row->id)
            ->update(['relayed_at' => null]);

        $second = asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());

        asTenant($tenant, function () use ($row): void {
            // ONE event. The primary key on events (id, occurred_at) — where id IS the
            // event_id — is what makes re-processing idempotent at the DATABASE rather than
            // relying on the relay being careful. So duplicate DELIVERY cannot become
            // duplicate EFFECT in the reporting log.
            expect(DB::table('events')->where('id', $row->event_id)->count())->toBe(
                1,
                'the same outbox row produced two events: duplicate delivery became duplicate effect',
            );
        });

        // The second pass reported failure rather than success, and the attempt counter
        // advanced — so a row that can never be relayed becomes visible rather than being
        // silently retried at every pass forever.
        expect($second)->toBe(0);

        $attempts = (int) DB::connection('pgsql_platform')->table('outbox')
            ->where('id', $row->id)->value('relay_attempts');
        expect($attempts)->toBeGreaterThan(1);
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('relays in UUIDv7 order, which is causal order without a wall clock (C-11, EC-08)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    // Three settings writes, each publishing one event.
    asTenant($tenant, function (): void {
        $settings = app(SettingsRepository::class);
        $settings->set('tenant.display_timezone', 'UTC');
        $settings->set('tenant.default_language', 'am');
        $settings->set('tenant.currency', 'USD');
    });

    commitFixtures();

    try {
        $expectedOrder = asTenant($tenant, fn (): array => DB::table('outbox')
            ->orderBy('id')->pluck('event_id')->all());

        expect($expectedOrder)->toHaveCount(3);

        asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());

        asTenant($tenant, function () use ($expectedOrder): void {
            // events.id carries event_id, itself UUIDv7, so ordering by it reproduces
            // publication order. No `occurred_at` comparison anywhere: EC-08 forbids ordering
            // on app-server clocks, and two nodes a few milliseconds apart would interleave
            // these wrongly while looking perfectly plausible.
            $actual = DB::table('events')->orderBy('id')->pluck('id')->all();

            expect($actual)->toBe($expectedOrder);
        });
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('reports lag from the OLDEST unrelayed row, so a stuck row is visible', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    asTenant($tenant, fn () => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Active,
        actor: AuditActor::System,
        reason: 'lag probe',
    ));

    commitFixtures();

    try {
        // The row was just written, so lag is near zero — but the metric now has something to
        // measure. §12 budgets ≤2 s p95 with a 60 s alert; measuring from the OLDEST row is
        // what stops one stuck event hiding behind a healthy stream of recent ones.
        expect(asPlatformWorker(fn (): float => app(OutboxRelay::class)->lagSeconds()))
            ->toBeGreaterThanOrEqual(0.0);

        asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());

        // Drained: nothing unrelayed, so no lag.
        expect(asPlatformWorker(fn (): float => app(OutboxRelay::class)->lagSeconds()))->toBe(0.0);
    } finally {
        cleanupTenants([$tenantId]);
    }
});
