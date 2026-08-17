<?php

declare(strict_types=1);

use App\Domain\Customers\Rollups\CustomerRollupStatus;
use App\Jobs\Customers\RollupConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FS-03 (S08 step 12) — the rollup STALENESS SURFACE (B4-S07 H07),
| on the Doc 07 ProjectorStatus pattern whole: stale means "the handler is
| behind" (beat-age past threshold WITH pending rollup-family work), never
| "nothing happened". Tests drive the STORED beat value — never a sleep.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** Drive the stored heartbeat to a chosen age. */
function driveRollupBeat(CustomerRollupStatus $status, int $ageSeconds): void
{
    Redis::connection('volatile')->command('set', [$status->key(), (string) (time() - $ageSeconds)]);
}

it('FS-03/H07: quiet stack ≠ outage — an aged beat with NO pending rollup-family work answers stale=false; the same age WITH an unrelayed customer event answers stale=true', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $status = app(CustomerRollupStatus::class);

    // Cold tenant: never beat — no staleness claim at all.
    expect($status->status())->toBe(['stale_seconds' => null, 'pending' => false, 'stale' => false]);

    // Aged beat, quiet stack: correct-as-of, not stale.
    driveRollupBeat($status, 300);
    $quiet = $status->status();
    expect($quiet['stale'])->toBeFalse('a quiet stack was called an outage — the ProjectorStatus lesson un-learned')
        ->and($quiet['pending'])->toBeFalse()
        ->and($quiet['stale_seconds'])->toBeGreaterThanOrEqual(299);

    // The same age WITH pending work: an unrelayed rollup-family outbox row
    // older than the threshold — now the claim is honest.
    DB::statement(
        "INSERT INTO outbox (id, tenant_id, event_id, name, schema_version, payload, occurred_at)
         VALUES (?, ?, ?, 'message.received', 1, '{}', clock_timestamp() - interval '200 seconds')",
        [(string) Str::uuid7(), $tenantId, (string) Str::uuid7()],
    );
    $behind = $status->status();
    expect($behind['stale'])->toBeTrue('an aged beat with pending rollup work was not called stale — H07 has no signal')
        ->and($behind['pending'])->toBeTrue();

    // A fresh beat clears the claim regardless of backlog age.
    driveRollupBeat($status, 0);
    expect($status->status()['stale'])->toBeFalse();
});

it('FS-03: the consumer BEATS on every applied event — and the panel door serves the whole H07 answer under rollups_stale', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $status = app(CustomerRollupStatus::class);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');

    // An applied customer event drives the heartbeat through the REAL consumer.
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'schema_version' => 1,
        'payload' => json_encode(['customer_id' => $customerId], JSON_THROW_ON_ERROR),
    ]);
    driveRollupBeat($status, 500); // stale before the consumer runs
    (new RollupConsumer($eventId))->handle(app(App\Domain\Customers\Rollups\CustomerRollups::class));
    expect($status->staleSeconds())->toBeLessThan(5, 'RollupConsumer did not beat — the staleness surface would lie forever');

    // The panel door carries the answer (the chip's one source).
    $viewer = ticketActor(['customers.view'], 'S08 Stale Panel');
    $panel = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel");
    $panel->assertOk();
    expect($panel->json('rollups_stale'))->toHaveKeys(['stale_seconds', 'pending', 'stale'])
        ->and($panel->json('rollups_stale.stale'))->toBeFalse();
});
