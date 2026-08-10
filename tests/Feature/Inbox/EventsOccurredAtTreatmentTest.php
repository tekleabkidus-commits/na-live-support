<?php

declare(strict_types=1);

use App\Support\Events\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A-33: the events-stream ordering and idempotency contract (Option B) —
| BUILD-STATUS row 9 discharged, corrected, at S07 step 1
|--------------------------------------------------------------------------
|
| THE CONTRACT (ruled at the step-1 checkpoint after C2's battery caught the
| as-filed treatment breaking C-09):
|   - events.occurred_at is the RELAY'S BYTE-COPY of outbox.occurred_at — DB
|     time at publish (the outbox default now()). The copy is what makes the
|     composite PK (id, occurred_at) refuse a re-driven duplicate: C-09 as
|     database physics, not relay care.
|   - The app role cannot NAME occurred_at (column grant — the migration's
|     default+grant governs DIRECT APP-SIDE writes; physics).
|   - The relay runs as platform_admin, which necessarily keeps table-level
|     INSERT — so WITHIN the platform role, "the supplied value is the outbox
|     copy" is pinned STRUCTURALLY (this file's diff assertion + the
|     byte-identical proof), stated honestly as such, not as grant physics.
|     Sabotage #21 (A-33's amendment): break the copy → these proofs go red.
|   - Ordered reads over events (Doc 07 T-07) get their guarantee from the
|     step-5 watermark + UUIDv7 within-stamp tiebreak (A-33 B-4), never from
|     the stamp being relay-time.
|
| The ruled-edit enumeration (B-3, citing row 9 + A-33):
|   1. app/Support/Events/OutboxRelay.php — the copy RESTORED after the
|      checkpoint (the C2 battery's find), with the A-33 contract comment.
|   2. database/migrations/2026_08_10_121000_events_occurred_at_db_time.php —
|      default clock_timestamp() + the app column grant (direct-write guard).
|   3. tests/Performance/FoundationBudgetsTest.php — fixture stops naming the
|      column (harness alignment, R-19a precedent).
| No other file touches the events write path.
*/

it('diff assertion (A-33/B-3, row 9): the relay supplies EXACTLY the outbox copy — and still inserts into events (positive control)', function (): void {
    $source = (string) file_get_contents(app_path('Support/Events/OutboxRelay.php'));

    // Positive control first: the scan is only meaningful while this file is
    // still the events writer.
    expect(str_contains($source, "table('events')->insert("))->toBeTrue(
        'OutboxRelay no longer inserts into events — this diff assertion is scanning the wrong writer and must move with it',
    );

    expect(str_contains($source, "'occurred_at' => \$row->occurred_at"))->toBeTrue(
        'the relay no longer copies outbox.occurred_at — the A-33 copy contract is broken; the events PK can no longer refuse a re-driven duplicate (C-09 physics gone)',
    );

    expect(str_contains($source, 'clock_timestamp'))->toBeFalse(
        'the relay names clock_timestamp somewhere — a fresh stamp on the events insert is sabotage #21\'s shape; the copy is the contract (A-33)',
    );
});

it('A-33 byte-identical proof: every relayed event carries ITS outbox row\'s stamp, verbatim — sabotage #21 goes red here', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    $keys = ['s07.a33.first', 's07.a33.second'];
    asTenant($tenant, function () use ($tenantId, $keys): void {
        foreach ($keys as $key) {
            app(App\Support\Events\EventPublisher::class)->publish(
                name: 'tenant.setting_changed',
                payload: ['tenant_id' => $tenantId, 'key' => $key],
            );
        }
    });

    commitFixtures();

    try {
        $relayed = asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
        expect($relayed)->toBeGreaterThanOrEqual(2);

        /** @var list<object{event_id: string, outbox_at: string, event_at: string}> $rows */
        $rows = asPlatformWorker(fn () => DB::connection('pgsql_platform')->select(
            'SELECT o.event_id, o.occurred_at AS outbox_at, e.occurred_at AS event_at
               FROM outbox o JOIN events e ON e.id = o.event_id
              WHERE o.tenant_id = ? AND o.name = ?
              ORDER BY o.id',
            [$tenantId, 'tenant.setting_changed'],
        ));

        expect($rows)->toHaveCount(2);
        foreach ($rows as $row) {
            expect($row->event_at)->toBe($row->outbox_at, sprintf(
                'event %s is stamped "%s" but its outbox row says "%s" — the relay supplied something other than the copy (A-33 broken; C-09 physics gone)',
                $row->event_id, $row->event_at, $row->outbox_at,
            ));
        }

        // Same publishing transaction → same DB stamp on BOTH (now() is
        // transaction-start): the documented within-stamp order is the
        // UUIDv7 id, per R-19's narrowing — asserted so the tie is a FACT
        // here, not an accident the step-5 cursor discovers.
        expect($rows[0]->event_at)->toBe($rows[1]->event_at,
            'two same-transaction publishes carry different stamps — the outbox default is no longer transaction-time now(); re-verify the A-33 contract end to end');
    } finally {
        asPlatformWorker(function () use ($tenantId): void {
            DB::connection('pgsql_platform')->table('events')->where('tenant_id', $tenantId)->delete();
            DB::connection('pgsql_platform')->table('outbox')->where('tenant_id', $tenantId)->delete();
        });
    }
});

it('B-1 positive control: the PK refusal ITSELF — a duplicate (id, occurred_at) insert throws unique_violation, and a re-driven row leaves count 1 with the refusal traced', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    asTenant($tenant, function () use ($tenantId): void {
        app(App\Support\Events\EventPublisher::class)->publish(
            name: 'tenant.setting_changed',
            payload: ['tenant_id' => $tenantId, 'key' => 's07.b1.control'],
        );
    });

    commitFixtures();

    try {
        expect(asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch()))->toBeGreaterThanOrEqual(1);

        /** @var object{id: string, event_id: string, occurred_at: string, relay_attempts: int} $outboxRow */
        $outboxRow = asPlatformWorker(fn () => DB::connection('pgsql_platform')
            ->table('outbox')->where('tenant_id', $tenantId)->where('name', 'tenant.setting_changed')->first());
        expect($outboxRow)->not->toBeNull();

        // (a) The error class, executed: a second insert with the SAME
        // composite key is refused by PostgreSQL itself — 23505.
        $caught = null;
        try {
            asPlatformWorker(fn (): bool => DB::connection('pgsql_platform')->table('events')->insert([
                'id' => $outboxRow->event_id,
                'tenant_id' => $tenantId,
                'name' => 'tenant.setting_changed',
                'schema_version' => 1,
                'payload' => json_encode(['tenant_id' => $tenantId, 'key' => 's07.b1.control'], JSON_THROW_ON_ERROR),
                'occurred_at' => $outboxRow->occurred_at,
            ]));
        } catch (Illuminate\Database\QueryException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull('the duplicate insert was ACCEPTED — the PK physics is gone');
        expect((string) $caught->getCode())->toBe('23505', 'the refusal was not unique_violation (23505) — something other than the PK refused');

        // (b) Through the REAL relay: force a re-drive of the already-relayed
        // row (the crash-after-insert-before-mark shape) and assert the
        // refusal's trace — one event, the row still unmarked, the attempt
        // counted. OutboxChaosTest owns the broader drill; this control pins
        // the refusal path specifically.
        $attemptsBefore = (int) $outboxRow->relay_attempts;
        asPlatformWorker(function () use ($outboxRow): void {
            DB::connection('pgsql_platform')->table('outbox')->where('id', $outboxRow->id)->update(['relayed_at' => null]);
        });
        asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());

        $after = asPlatformWorker(fn () => DB::connection('pgsql_platform')
            ->table('outbox')->where('id', $outboxRow->id)->first());
        $eventCount = asPlatformWorker(fn (): int => DB::connection('pgsql_platform')
            ->table('events')->where('id', $outboxRow->event_id)->count());

        expect($eventCount)->toBe(1, 'the re-driven row produced a second event — duplicate delivery became duplicate effect');
        expect((int) $after->relay_attempts)->toBeGreaterThan($attemptsBefore,
            'the refusal left no trace — relay_attempts did not move, so the failure path did not run');
    } finally {
        asPlatformWorker(function () use ($tenantId): void {
            DB::connection('pgsql_platform')->table('events')->where('tenant_id', $tenantId)->delete();
            DB::connection('pgsql_platform')->table('outbox')->where('tenant_id', $tenantId)->delete();
        });
    }
});

it('the app role writes events only through the default: naming occurred_at is refused, omitting it fills distinct db-time stamps', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();

    asTenant($tenant, function () use ($tenantId): void {
        // Refusal half — inside a savepoint so the aborted statement cannot
        // poison the harness transaction.
        expect(fn () => DB::transaction(fn (): bool => DB::table('events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'name' => 'tenant.setting_changed',
            'schema_version' => 1,
            'payload' => json_encode(['tenant_id' => $tenantId, 'key' => 's07.denied'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ])))->toThrow(Illuminate\Database\QueryException::class);

        // Default half — two DIRECT app inserts in ONE transaction get
        // DISTINCT stamps, which only clock_timestamp() can produce here.
        // (Direct app writes are test-only; production events all arrive via
        // the relay's copy path — the A-33 topology statement.)
        $ids = [(string) Str::uuid7(), (string) Str::uuid7()];
        foreach ($ids as $id) {
            DB::table('events')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'name' => 'tenant.setting_changed',
                'schema_version' => 1,
                'payload' => json_encode(['tenant_id' => $tenantId, 'key' => 's07.default'], JSON_THROW_ON_ERROR),
            ]);
        }

        $stamps = DB::table('events')->whereIn('id', $ids)->orderBy('id')->pluck('occurred_at')->all();
        expect($stamps)->toHaveCount(2)
            ->and($stamps[0])->not->toBe($stamps[1],
                'two same-transaction inserts share one stamp — the default is transaction-start time (now()), not clock_timestamp()');
    });
});
