<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Customers\Identity\IdentityResolution;
use App\Domain\Customers\Search\CustomerSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FS-01 (S08 step 8) — Meilisearch down: directory search DEGRADES to
| a LABELED DB prefix search on names/phone through the SAME scope brain;
| never a 500, never a scope widening. (FR-20's table door and EC-08 join
| this file at the directory steps; FS-01's "panel unaffected" clause is
| proven when T-07's panel endpoint lands at step 9 — the panel reads the
| DB, not this index, by construction.)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** A staff-linked phone identity, directly at the storage layer (the FS-01 fixtures need the value, not the door). */
function directoryPhoneIdentity(string $tenantId, string $customerId, string $e164): void
{
    // The deprecated channel/external_user_id pair stays NOT NULL through the
    // C-12 contract window — filled the way LinkIdentity fills it.
    DB::table('customer_identities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'customer_id' => $customerId,
        'channel' => 'phone', 'external_user_id' => $e164,
        'type' => 'phone', 'value' => $e164, 'source' => 'staff',
    ]);
}

it('FS-01: Meilisearch down → the dead-port drill — a LABELED degrade (degraded: true) answering DB prefix hits on name AND phone, still scope-constrained by the same brain', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $all = customersScopedActor('All', 'S08 Dir FS1 All');
    $own = customersScopedActor('Own', 'S08 Dir FS1 Own');

    $named = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $named)->update(['display_name' => 'Selamawit Dirfixture']);
    directoryPhoneIdentity($tenantId, $named, '+251900001234');

    // The sabotage-#11 idiom (S07 precedent): a dead port, not a mock.
    config()->set('services.meilisearch.host', 'http://127.0.0.1:59999');

    $search = app(CustomerSearch::class);

    // Name prefix, labeled.
    $byName = $search->search('Selamaw', $all);
    expect($byName['degraded'])->toBeTrue('the outage was not labeled — FS-01 requires the degrade to say so')
        ->and(array_column($byName['hits'], 'id'))->toBe([$named]);

    // Phone prefix (the document's own fallback list: names/phone).
    $byPhone = $search->search('+2519000012', $all);
    expect($byPhone['degraded'])->toBeTrue()
        ->and(array_column($byPhone['hits'], 'id'))->toBe([$named]);

    // The degrade rides the SAME CustomerScope constraint: an Own-scoped
    // viewer with no assigned ticket for this customer still sees NOTHING —
    // an outage never widens scope (the one-brain proof).
    $scoped = $search->search('Selamaw', $own);
    expect($scoped['degraded'])->toBeTrue()
        ->and($scoped['hits'])->toBe([], 'the degrade path leaked an out-of-scope customer — FS-01 forked a second scope engine');

    // The HTTP door: 200 with the label in-band, never a 500.
    $response = test()->actingAs($all, 'staff')->getJson('https://localhost/api/staff/customers/search?q=Selamaw');
    $response->assertOk();
    expect($response->json('degraded'))->toBeTrue()
        ->and(array_column($response->json('hits'), 'id'))->toBe([$named]);
});

/*
|--------------------------------------------------------------------------
| Doc 08 FR-20 / EC-08 / SEC-01 / C-05 (S08 step 12) — the TABLE DOOR.
| Directory rows, filters, cursor pages, and G03's law proven at the SQL
| level: for a viewer without customers.rg_flag.view the rg column is never
| NAMED in any executed statement, the rg parameter has no code path, and
| the scope count is identical for both viewers.
|--------------------------------------------------------------------------
*/

/** Seed a directory scene customer with chosen surface columns. */
function directoryCustomer(string $tenantId, string $name, array $columns = [], ?string $lastContact = null): string
{
    $id = enforcedCustomer($tenantId, '2026-06-01 09:00:00+00');
    DB::table('customers')->where('id', $id)->update(array_merge([
        'display_name' => $name,
        'is_shell' => false,
        'last_contact_at' => $lastContact,
    ], $columns));

    return $id;
}

it('FR-20/EC-08: the table door — rows ordered last-contact DESC NULLS LAST, every filter narrowing, short ids on every row, tombstones absent, phone identities displayed national', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = customersScopedActor('All', 'S08 Dir Table All');

    $recent = directoryCustomer($tenantId, 'Dir Recent', ['language' => 'am', 'open_tickets' => 2], '2026-06-10 10:00:00+00');
    $older = directoryCustomer($tenantId, 'Dir Older', ['country' => 'ET', 'blocked' => true, 'blocked_reason' => 'fixture'], '2026-06-05 10:00:00+00');
    $never = directoryCustomer($tenantId, 'Dir Nevercontact');
    DB::statement("UPDATE customers SET tags = ARRAY['vip']::text[] WHERE id = ?", [$recent]);
    directoryPhoneIdentity($tenantId, $older, '+251900001234');

    // A tombstone NEVER lists (BR-05: it redirects; it is not a row).
    $tombstone = directoryCustomer($tenantId, 'Dir Tombstone', [], '2026-06-11 10:00:00+00');
    DB::table('customers')->where('id', $tombstone)->update(['merged_into_id' => $recent]);

    $page = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory');
    $page->assertOk();
    expect(array_column($page->json('items'), 'id'))->toBe([$recent, $older, $never], 'ordering or tombstone exclusion broke')
        ->and($page->json('total'))->toBe(3)
        ->and($page->json('items.0.short_id'))->toBe(substr($recent, -4))
        ->and($page->json('items.0.tags'))->toBe(['vip'])
        ->and($page->json('items.1.identities.0.type'))->toBe('phone')
        ->and($page->json('items.1.identities.0.display'))->not->toContain('+251');

    // Each filter narrows to its own row set; the header count NEVER moves.
    $hasOpen = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory?has_open=1');
    expect(array_column($hasOpen->json('items'), 'id'))->toBe([$recent])->and($hasOpen->json('total'))->toBe(3);
    $byLanguage = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory?language=am');
    expect(array_column($byLanguage->json('items'), 'id'))->toBe([$recent]);
    $byCountry = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory?country=ET');
    expect(array_column($byCountry->json('items'), 'id'))->toBe([$older]);
    $byTag = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory?tag=vip');
    expect(array_column($byTag->json('items'), 'id'))->toBe([$recent]);
    $byBlocked = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory?blocked=1');
    expect(array_column($byBlocked->json('items'), 'id'))->toBe([$older]);
});

it('G03/SEC-01/BR-03: for a viewer WITHOUT customers.rg_flag.view the rg column is never NAMED in SQL, the rg parameter changes nothing byte-for-byte, no item carries the key, and the count matches the permitted viewer\'s exactly', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $flagged = directoryCustomer($tenantId, 'Dir Rg Flagged', ['rg_flag' => true], '2026-06-10 10:00:00+00');
    $plain = directoryCustomer($tenantId, 'Dir Rg Plain', [], '2026-06-09 10:00:00+00');

    $unpermitted = ticketActor(['customers.view'], 'S08 Dir NoRg');
    $permitted = ticketActor(['customers.view', 'customers.rg_flag.view'], 'S08 Dir Rg');

    // The SQL-level proof: capture every statement the request executes.
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $bare = test()->actingAs($unpermitted, 'staff')->getJson('https://localhost/api/staff/customers/directory');
    $bare->assertOk();
    $rgNaming = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'rg_flag')));
    expect($rgNaming)->toBe([], 'the rg column was NAMED in SQL for an unpermitted viewer — G03\'s query-level law is broken');

    // The rg parameter has NO code path: byte-identical response.
    $withParam = test()->actingAs($unpermitted, 'staff')->getJson('https://localhost/api/staff/customers/directory?rg=1');
    expect($withParam->getContent())->toBe($bare->getContent(), 'the rg parameter changed an unpermitted viewer\'s table — a filter that exists but returns nothing is a disclosure');

    expect($bare->json('filters.rg_available'))->toBeFalse()
        ->and(array_column($bare->json('items'), 'id'))->toBe([$flagged, $plain]);
    foreach ($bare->json('items') as $item) {
        expect(array_key_exists('rg_flag', $item))->toBeFalse('an item carried the rg key (absence, never null — BR-03)');
    }

    // The permitted viewer: same COUNT, the filter real, the key present.
    $permittedPage = test()->actingAs($permitted, 'staff')->getJson('https://localhost/api/staff/customers/directory');
    expect($permittedPage->json('total'))->toBe($bare->json('total'), 'the header count differed between viewers — G03\'s count parity');
    expect($permittedPage->json('filters.rg_available'))->toBeTrue()
        ->and($permittedPage->json('items.0.rg_flag'))->toBeTrue()
        ->and($permittedPage->json('items.1.rg_flag'))->toBeFalse();
    $filtered = test()->actingAs($permitted, 'staff')->getJson('https://localhost/api/staff/customers/directory?rg=1');
    expect(array_column($filtered->json('items'), 'id'))->toBe([$flagged]);
});

it('C-05: cursor pages walk the whole set exactly once — dated rows, then the null tail — and limit refuses past 100', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = customersScopedActor('All', 'S08 Dir Cursor All');

    $expected = [];
    foreach (range(1, 5) as $i) {
        $expected[] = directoryCustomer($tenantId, "Dir Cursor {$i}", [], sprintf('2026-06-%02d 10:00:00+00', 20 - $i));
    }
    $expected[] = directoryCustomer($tenantId, 'Dir Cursor Null A');
    $expected[] = directoryCustomer($tenantId, 'Dir Cursor Null B');

    $seen = [];
    $cursor = null;
    do {
        $url = 'https://localhost/api/staff/customers/directory?limit=3'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
        $page = test()->actingAs($viewer, 'staff')->getJson($url);
        $page->assertOk();
        foreach (array_column($page->json('items'), 'id') as $id) {
            expect($seen)->not->toContain($id, 'a row appeared on two pages — the cursor overlapped');
            $seen[] = $id;
        }
        $cursor = $page->json('next_cursor');
    } while ($cursor !== null);

    expect(count($seen))->toBe(7, 'the cursor walk missed rows');
    expect(array_diff($expected, $seen))->toBe([], 'a seeded row never surfaced');

    test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory?limit=101')
        ->assertStatus(422);
});

it('FR-20 scope: an Own-scoped viewer\'s table AND count hold only customers with a ticket assigned to them — the directory rides the ONE scope brain', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $own = customersScopedActor('Own', 'S08 Dir Own');

    $mine = directoryCustomer($tenantId, 'Dir Scope Mine', [], '2026-06-10 10:00:00+00');
    $other = directoryCustomer($tenantId, 'Dir Scope Other', [], '2026-06-11 10:00:00+00');
    $conversation = anchoredConversation($tenantId, $mine, 'dir-scope-1');
    $ticket = rollupTicket($tenantId, $conversation, $mine, 'open');
    DB::table('tickets')->where('id', $ticket)->update(['assignee_id' => $own->identifier()]);

    $page = test()->actingAs($own, 'staff')->getJson('https://localhost/api/staff/customers/directory');
    $page->assertOk();
    expect(array_column($page->json('items'), 'id'))->toBe([$mine], 'the directory leaked an out-of-scope customer')
        ->and($page->json('total'))->toBe(1, 'the header count widened past the viewer\'s scope');
});

it('G04: a hit found by a RENAMED username explains itself — matched.on=username_history with the old value, its stamp, and the current name', function (): void {
    createTenant();
    $viewer = customersScopedActor('All', 'S08 Dir Matched');
    $resolver = app(IdentityResolution::class);

    $customerId = $resolver->resolveInbound('telegram', new InboundSender('dir-hist-1', 'Matched Fixture', '@fixture_gerenuk55'))->customerId;
    $resolver->resolveInbound('telegram', new InboundSender('dir-hist-1', 'Matched Fixture', '@fixture_bushbuck66'));
    indexCustomer($customerId);

    try {
        $result = app(CustomerSearch::class)->search('gerenuk55', $viewer);
        expect($result['degraded'])->toBeFalse();
        $hit = $result['hits'][0] ?? null;
        expect($hit)->not->toBeNull('the historical username no longer hits');
        expect($hit['matched']['on'])->toBe('username_history', 'the hit did not explain its historical match — G04')
            ->and($hit['matched']['value'])->toContain('gerenuk55')
            ->and($hit['matched']['used_until'])->not->toBeNull('the history stamp is missing')
            ->and($hit['matched']['current_username'])->toContain('bushbuck66');

        // The positive control: a CURRENT-value match says so too.
        $current = app(CustomerSearch::class)->search('bushbuck66', $viewer);
        expect($current['hits'][0]['matched']['on'])->toBe('username');
    } finally {
        dropCustomersSearchIndex();
    }
});

it('FR-01: a customer named only by their CHANNEL still renders and searches by that name — the derivation chain (staff → channel display → username) is ONE brain across the table door and the search documents', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = customersScopedActor('All', 'S08 Dir Derived');
    $resolver = app(IdentityResolution::class);

    // Minted by the channel, never staff-named.
    $channelNamed = $resolver->resolveInbound('telegram', new InboundSender('dir-derived-1', 'Channel Named', '@fixture_derived_a'))->customerId;

    // The search document derives the name (before the HTTP call — the
    // request cycle clears this connection's tenant GUC).
    indexCustomer($channelNamed);
    try {
        $hits = app(CustomerSearch::class)->search('Channel Named', $viewer)['hits'];
        // (unfindable = the search document skipped the derivation)
        expect(array_column($hits, 'id'))->toContain($channelNamed);
    } finally {
        dropCustomersSearchIndex();
    }

    $page = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/customers/directory');
    $row = collect($page->json('items'))->firstWhere('id', $channelNamed);
    expect($row)->not->toBeNull()
        ->and($row['display_name'])->toBe('Channel Named', 'the table door skipped FR-01\'s derivation — a channel-named customer rendered nameless');
});
