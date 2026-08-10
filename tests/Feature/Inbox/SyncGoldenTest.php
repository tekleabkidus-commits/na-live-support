<?php

declare(strict_types=1);

use App\Domain\Inbox\Sync\SyncReader;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-07 / NFR-06 / A-33 B-4 (S07 step 5): the reconnect goldens. Every stamp
| below is CHOSEN and written via the platform escape hatch (the R-19a
| fixture path) — no sleeps, no wall-clock asserts. SABOTAGE #10's TARGETS.
|--------------------------------------------------------------------------
*/

/** An events row with a CHOSEN stamp (platform-side; app cannot name occurred_at). */
function syncSeed(string $tenantId, string $offsetSql, array $payload = [], string $name = 'tenant.setting_changed'): string
{
    $id = (string) Str::uuid7();
    asPlatformWorker(function () use ($id, $tenantId, $name, $payload, $offsetSql): void {
        DB::connection('pgsql_platform')->insert(
            "INSERT INTO events (id, tenant_id, name, schema_version, payload, occurred_at)
             VALUES (?, ?, ?, 1, ?, clock_timestamp() - interval '{$offsetSql}')",
            [$id, $tenantId, $name, json_encode(['tenant_id' => $tenantId] + $payload, JSON_THROW_ON_ERROR)],
        );
    });

    return $id;
}

function syncReader(int $watermark = SyncReader::WATERMARK_SECONDS, int $max = SyncReader::MAX_EVENTS): SyncReader
{
    return new SyncReader(app(ScopeSets::class), $watermark, $max);
}

it('B-4 ordering: events replay in (occurred_at, id) with UUIDv7 as the WITHIN-STAMP tiebreak only — a shared stamp orders by id, distinct stamps never do (sabotage #10 goes red here)', function (): void {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $viewer = inboxScopedActor('All', 'S07 Sync All');
    // Chosen stamps ride the platform escape hatch, which cannot see the
    // harness transaction — commit first, clean up in finally (the
    // OutboxChaos idiom).
    commitFixtures();

    try {
        // Three stamps: -60 s, a SHARED -40 s pair (same publish transaction —
        // the A-33 tie), -20 s. Insertion order deliberately scrambled.
        $late = syncSeed($tenantId, '20 seconds', ['key' => 's5.late']);
        $tieA = (string) Str::uuid7();
        $tieB = (string) Str::uuid7();
        [$tieFirst, $tieSecond] = strcmp($tieA, $tieB) < 0 ? [$tieA, $tieB] : [$tieB, $tieA];
        asPlatformWorker(function () use ($tieA, $tieB, $tenantId): void {
            DB::connection('pgsql_platform')->statement(
                "WITH shared AS (SELECT clock_timestamp() - interval '40 seconds' AS at)
             INSERT INTO events (id, tenant_id, name, schema_version, payload, occurred_at)
             SELECT x.id, ?, 'tenant.setting_changed', 1, x.payload, shared.at
               FROM shared, (VALUES (?::uuid, ?::jsonb), (?::uuid, ?::jsonb)) AS x(id, payload)",
                [$tenantId,
                    $tieA, json_encode(['tenant_id' => $tenantId, 'key' => 's5.tieA'], JSON_THROW_ON_ERROR),
                    $tieB, json_encode(['tenant_id' => $tenantId, 'key' => 's5.tieB'], JSON_THROW_ON_ERROR)],
            );
        });
        $early = syncSeed($tenantId, '60 seconds', ['key' => 's5.early']);

        $result = asTenant($tenant, fn (): array => syncReader()->since(null, $viewer));
        $ids = array_column($result['events'], 'id');

        expect($ids)->toBe([$early, $tieFirst, $tieSecond, $late],
            'the replay is not (occurred_at, id): stamps must order first; the UUIDv7 id breaks ONLY the shared-stamp tie (A-33 B-4 / R-19\'s narrowing)');
        expect($result['overflow'])->toBeFalse()
            ->and($result['server_now'])->not->toBeNull();
    } finally {
        asPlatformWorker(fn () => DB::connection('pgsql_platform')->table('events')->where('tenant_id', $tenantId)->delete());
    }
});

it('B-4 watermark + long-transaction no-miss: a laggard stamped INSIDE the zone is invisible until settled, the cursor never passes it, and it replays IN ORDER once settled — the freshness trade-off executed', function (): void {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $viewer = inboxScopedActor('All', 'S07 Sync WM');
    commitFixtures();

    try {
        $settled = syncSeed($tenantId, '30 seconds', ['key' => 's5.settled']);
        // THE LONG-TRANSACTION FIXTURE: stamped 8 s ago (a publish transaction
        // that opened then, still inside the 10 s watermark zone at first sync —
        // exactly the commit-skew shape the watermark exists for).
        $laggard = syncSeed($tenantId, '8 seconds', ['key' => 's5.laggard']);

        // First sync (W=10): the settled event only. The laggard is NOT served
        // and — decisively — the cursor does NOT advance past its stamp.
        $first = asTenant($tenant, fn (): array => syncReader()->since(null, $viewer));
        expect(array_column($first['events'], 'id'))->toBe([$settled],
            'the watermark zone leaked — sync served an unsettled event (the freshness trade-off is gone)');

        // Second sync, zone passed (W=5 makes the -8 s stamp settled WITHOUT
        // sleeping — the property under test is ordering/no-miss, not wall time).
        $second = asTenant($tenant, fn (): array => syncReader(watermark: 5)->since($first['next_cursor'], $viewer));
        expect(array_column($second['events'], 'id'))->toBe([$laggard],
            'NO-MISS failed: the laggard never replayed — the cursor advanced past a stamp that had not settled');
    } finally {
        asPlatformWorker(fn () => DB::connection('pgsql_platform')->table('events')->where('tenant_id', $tenantId)->delete());
    }
});

it('A-31 scope golden: a scope-excluded ticket\'s event NEVER reaches the narrower client; the visible one does; tenant-wide events pass', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $own = inboxScopedActor('Own', 'S07 Sync Own');
    $other = App\Models\User::factory()->create();

    // Rows the scope predicate reads (A-31 joins through ticket_list_items).
    $cMine = anotherConversation($tenantId, 'sync-mine');
    [$tMine] = inboxTicketRow($tenantId, $cMine, 'open', ['assignee_id' => $own->identifier()]);
    $cTheirs = anotherConversation($tenantId, 'sync-theirs');
    [$tTheirs] = inboxTicketRow($tenantId, $cTheirs, 'open', ['assignee_id' => $other->identifier()]);
    foreach ([[$ticket, $conversation], [$tMine, $cMine], [$tTheirs, $cTheirs]] as [$t, $c]) {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    }

    commitFixtures();

    try {
        $mineEvent = syncSeed($tenantId, '30 seconds', ['ticket_id' => $tMine->identifier(), 'conversation_id' => $cMine->identifier(), 'old_priority' => 'normal', 'new_priority' => 'high', 'actor_type' => 'agent', 'actor_id' => null], 'ticket.priority_changed');
        $theirsEvent = syncSeed($tenantId, '29 seconds', ['ticket_id' => $tTheirs->identifier(), 'conversation_id' => $cTheirs->identifier(), 'old_priority' => 'normal', 'new_priority' => 'high', 'actor_type' => 'agent', 'actor_id' => null], 'ticket.priority_changed');
        $tenantWide = syncSeed($tenantId, '28 seconds', ['channel' => 'telegram', 'channel_ref_id' => 'bot-sync-1', 'status' => 'down'], 'channel.health.changed');

        $result = asTenant($tenant, fn (): array => syncReader()->since(null, $own));
        $ids = array_column($result['events'], 'id');

        expect(in_array($mineEvent, $ids, true))->toBeTrue('the viewer lost their OWN ticket\'s event')
            ->and(in_array($theirsEvent, $ids, true))->toBeFalse('A-31 BROKEN: a scope-excluded event reached the narrower client')
            ->and(in_array($tenantWide, $ids, true))->toBeTrue('a tenant-wide workspace event was scope-dropped');
    } finally {
        asPlatformWorker(fn () => DB::connection('pgsql_platform')->table('events')->where('tenant_id', $tenantId)->delete());
    }
});

it('T-07 overflow: past the bound the response INSTRUCTS a full refetch — no partial page pretends to be a replay', function (): void {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $viewer = inboxScopedActor('All', 'S07 Sync Overflow');
    commitFixtures();

    try {
        foreach (range(1, 4) as $i) {
            syncSeed($tenantId, (20 + $i).' seconds', ['key' => "s5.of{$i}"]);
        }

        $result = asTenant($tenant, fn (): array => syncReader(max: 3)->since(null, $viewer));
        expect($result['overflow'])->toBeTrue()
            ->and($result['events'])->toBe([])
            ->and($result['next_cursor'])->toBeNull();
    } finally {
        asPlatformWorker(fn () => DB::connection('pgsql_platform')->table('events')->where('tenant_id', $tenantId)->delete());
    }
});

it('the HTTP contract: v:1 envelope with server_now (EC-07\'s anchor, DB time) and build_id (FR-30); EC-08\'s 429-on-connect fires past the limiter', function (): void {
    [$tenant] = ticketFixture('open');
    activateTenant($tenant);
    $viewer = inboxScopedActor('All', 'S07 Sync HTTP');

    $response = $this->actingAs($viewer, 'staff')->getJson('https://localhost/inbox/sync');
    $response->assertOk();
    expect($response->json('v'))->toBe(1)
        ->and($response->json('build_id'))->toBe('dev')
        ->and($response->json('server_now'))->not->toBeNull()
        ->and($response->json('overflow'))->toBeFalse();

    // EC-07: the anchor is DB time — within a coarse honesty band of the
    // database's own now (both sides DB-derived; no client clock involved).
    $serverNow = new DateTimeImmutable((string) $response->json('server_now'));
    $dbNow = new DateTimeImmutable((string) DB::selectOne('SELECT clock_timestamp() AS t')->t);
    expect(abs($dbNow->getTimestamp() - $serverNow->getTimestamp()))->toBeLessThan(30);

    // EC-08: exhaust the limiter → 429 with Retry-After (the client's jitter
    // contract hangs off this; the client half is step 13).
    $status = 200;
    foreach (range(1, 35) as $i) {
        $status = $this->actingAs($viewer, 'staff')->getJson('https://localhost/inbox/sync')->status();
        if ($status === 429) {
            break;
        }
    }
    expect($status)->toBe(429, 'the reconnect-storm limiter never fired — EC-08\'s 429-on-connect contract is unenforced');
});
