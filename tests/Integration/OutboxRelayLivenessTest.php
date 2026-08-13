<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| S07 step 14 — the outbox relay LIVENESS proof (advisor ruling condition 2,
| on the step-14 discovery that no process ever ran the relay).
|--------------------------------------------------------------------------
|
| THE CLAIM UNDER PROOF: a freshly booted stack RELAYS — a committed outbox
| row reaches the events log within the documents' own budget, moved by the
| dedicated `relay` compose service (not by this test, not by an inline
| consumer).
|
| N, DERIVED AND STATED (the ruling's demand): T-02/FR-11 promise live list
| updates batched at ≤5 s over a live feed. The relay is the FIRST leg of
| that pipeline — outbox → events log → consumer → Reverb → client batch —
| so a relay leg longer than the WHOLE 5 s promise is structurally late no
| matter what the other legs do. N = 5 s is therefore the ceiling this test
| asserts; the report states the measured figure (idle-load reality is well
| under it, and drift toward the ceiling is a §12 lag-budget conversation).
|
| MECHANICS: every Pest test runs inside a rolled-back app-connection
| transaction, which the sibling relay process could never observe — so the
| fixture row is written through the PLATFORM connection (committed
| immediately) under the fixture tenant, with a self-identifying synthetic
| name no consumer subscribes to. The row it leaves behind is synthetic,
| tiny, and named for what it is (CLAUDE.md §8 posture).
|
| THE KILL PAIR (ruling condition 2ii) runs from the HOST via ci/capture.sh:
| `docker compose stop relay` → THIS TEST GOES RED → `start` → green. The
| captures live in docs/evidence/S07-step14/.
*/

const RELAY_BUDGET_SECONDS = 5.0;

it('RELAY LIVENESS: a committed outbox row reaches the events log within the documents\' own 5 s budget — moved by the dedicated relay service, not by this process', function (): void {
    /** @var object{id: string}|null $tenant */
    $tenant = DB::connection('pgsql_platform')->table('tenants')->first(['id']);
    if ($tenant === null) {
        $this->markTestSkipped('no tenant exists — a fresh stack seeds one with the browser fixtures');
    }

    $eventId = (string) Str::uuid7();
    DB::connection('pgsql_platform')->table('outbox')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'event_id' => $eventId,
        'name' => 'fixture.relay_liveness',
        'schema_version' => 1,
        'payload' => json_encode(['probe' => 'relay-liveness', 'synthetic' => true], JSON_THROW_ON_ERROR),
        'correlation_id' => (string) Str::uuid7(),
        'occurred_at' => DB::raw('clock_timestamp()'),
    ]);

    $start = microtime(true);
    $seen = false;
    while (microtime(true) - $start < RELAY_BUDGET_SECONDS) {
        $seen = DB::connection('pgsql_platform')->table('events')->where('id', $eventId)->exists();
        if ($seen) {
            break;
        }
        usleep(200_000);
    }
    $elapsed = microtime(true) - $start;

    expect($seen)->toBeTrue(sprintf(
        'the relay did not move a committed outbox row into the events log within %.1f s — '
        .'either the relay service is down (the FS-01 condition; is the `relay` compose service running?) '
        .'or it is slower than the T-02/FR-11 budget its pipeline must fit inside',
        RELAY_BUDGET_SECONDS,
    ));

    // The measured figure, stated (the ruling's "derived and stated" bar).
    fwrite(STDERR, sprintf("\nRELAY LIVENESS: relayed in %.2f s (budget %.1f s — T-02/FR-11's 5 s live promise bounds the whole pipeline)\n", $elapsed, RELAY_BUDGET_SECONDS));
});
