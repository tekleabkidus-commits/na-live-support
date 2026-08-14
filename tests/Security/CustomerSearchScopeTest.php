<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Customers\Identity\IdentityResolution;
use App\Domain\Customers\Search\CustomerSearch;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AC-07 / T-04 / SEC-02 (S08 step 8) — the scope-injection proofs, EXECUTED
| BEFORE customers_index serves real data (the plan's ordered minimum; the
| Doc 03 AC-03 reuse, the S07 InboxSearchScopeTest shape on the second
| index). RLS does not reach a search index: BOTH tenancy layers are code —
| the physical per-tenant index and the server-injected customers-module
| scope filter (sabotage #9's two targets).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('AC-07 layer 1: tenant A NEVER sees tenant B\'s indexed customers — the physical index name is tenant-derived, server-side', function (): void {
    // Tenant B, with a distinctively named indexed customer.
    $tenantB = createTenant();
    $customerB = enforcedCustomer($tenantB->identifier(), '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerB)->update(['display_name' => 'Zebra Crossline']);
    indexCustomer($customerB);

    // Tenant A searches the SAME distinctive term with the WIDEST scope.
    $tenantA = createTenant();
    $viewerA = customersScopedActor('All', 'S08 CSearch XT');

    try {
        $result = app(CustomerSearch::class)->search('Zebra', $viewerA);

        expect($result['degraded'])->toBeFalse('tenant A\'s search degraded instead of answering — the isolation claim below would be vacuous');
        expect($result['hits'])->toBe([], 'CROSS-TENANT SEARCH HIT: tenant B\'s customer surfaced in tenant A\'s results (N-01 at the index layer)');

        // Positive control: tenant B FINDS its own customer — the empty
        // result above is isolation, not a broken index.
        $hitIds = asTenant($tenantB, function (): array {
            $viewerB = customersScopedActor('All', 'S08 CSearch B');

            return array_column(app(CustomerSearch::class)->search('Zebra', $viewerB)['hits'], 'id');
        });
        expect(in_array($customerB, $hitIds, true))->toBeTrue('the positive control failed — nothing was indexed at all');
    } finally {
        dropCustomersSearchIndex();
        asTenant($tenantB, fn () => dropCustomersSearchIndex());
    }
});

it('AC-07 layer 2: the customers-module scope filter is INJECTED server-side — Own sees exactly their customer, All both, a silent scope map NOTHING (fail-closed)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $own = customersScopedActor('Own', 'S08 CSearch Own');
    $all = customersScopedActor('All', 'S08 CSearch All');
    $none = customersScopedActor('none', 'S08 CSearch None');

    // Customer MINE: a ticket assigned to the Own viewer anchors the scope.
    $mine = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $mine)->update(['display_name' => 'Quagga Alpha']);
    $convMine = anotherConversation($tenantId, 'cs-own', $mine);
    inboxTicketRow($tenantId, $convMine, 'open', ['assignee_id' => $own->identifier()]);

    // Customer OTHER: same searchable term, ticket UNASSIGNED — outside Own.
    $other = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    DB::table('customers')->where('id', $other)->update(['display_name' => 'Quagga Beta']);
    $convOther = anotherConversation($tenantId, 'cs-other', $other);
    inboxTicketRow($tenantId, $convOther, 'open');

    indexCustomer($mine);
    indexCustomer($other);

    try {
        $search = app(CustomerSearch::class);

        expect(array_column($search->search('Quagga', $own)['hits'], 'id'))
            ->toBe([$mine], 'an out-of-scope customer hit returned — the injected filter is gone (AC-07)');

        // Positive control: All-scope sees both.
        expect(array_column($search->search('Quagga', $all)['hits'], 'id'))
            ->toEqualCanonicalizing([$mine, $other]);

        // A role whose scope map is SILENT about customers: the empty set,
        // with no Meilisearch round-trip to leak through (NFR-04's posture).
        expect($search->search('Quagga', $none))->toBe(['degraded' => false, 'hits' => []]);
    } finally {
        dropCustomersSearchIndex();
    }
});

it('SEC-02: the HTTP door accepts a QUERY STRING and nothing else — a client-sent filter/scope/index parameter changes NOTHING (no code path exists)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $own = customersScopedActor('Own', 'S08 CSearch HTTP Own');

    // The only matching customer is OUTSIDE Own scope (unassigned ticket).
    $other = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $other)->update(['display_name' => 'Okapi Bonus']);
    inboxTicketRow($tenantId, anotherConversation($tenantId, 'cs-http', $other), 'open');
    indexCustomer($other);

    try {
        // The attacker-shaped request smuggles every plausible scope-widening
        // parameter — and gets the same empty answer, because the door reads
        // NONE of them (q is the whole request contract).
        $response = test()->actingAs($own, 'staff')->getJson(
            'https://localhost/api/staff/customers/search?q=Okapi&filter=assignee_ids%20IS%20NULL&scope=all&index=t_other&department_id=*'
        );
        $response->assertOk();
        expect($response->json('hits'))->toBe([], 'a client-sent parameter widened search scope — SEC-02\'s one-path contract is broken')
            ->and($response->json('degraded'))->toBeFalse();
    } finally {
        dropCustomersSearchIndex();
    }
});

it('AC-07: HISTORICAL usernames hit (FR-06) — a renamed Telegram identity is findable by the name it no longer carries; the upsert stays one document (C-09)', function (): void {
    createTenant();
    $viewer = customersScopedActor('All', 'S08 CSearch Hist');
    $resolver = app(IdentityResolution::class);

    // Minted through the REAL resolver, then renamed through it — the
    // history entry searched below is the one FR-06 actually writes.
    $customerId = $resolver->resolveInbound('telegram', new InboundSender('cs-hist-1', 'Hist Fixture', '@fixture_okapi77'))->customerId;
    $resolver->resolveInbound('telegram', new InboundSender('cs-hist-1', 'Hist Fixture', '@fixture_takin88'));

    indexCustomer($customerId);
    indexCustomer($customerId); // the C-09 shape: a replay rewrites, never duplicates

    try {
        $search = app(CustomerSearch::class);

        expect(array_column($search->search('okapi77', $viewer)['hits'], 'id'))
            ->toBe([$customerId], 'the OLD username no longer hits — FR-06\'s history is not indexed (AC-07\'s historical clause)');
        expect(array_column($search->search('takin88', $viewer)['hits'], 'id'))
            ->toBe([$customerId], 'the CURRENT username does not hit');
    } finally {
        dropCustomersSearchIndex();
    }
});

it('AC-07 / §14: Amharic name search returns the expected customers on the seeded corpus — including a SECOND-word hit (Meilisearch Ethiopic tokenization validated)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $viewer = customersScopedActor('All', 'S08 CSearch Amharic');

    /** @var array{customers: list<array{display_name: string, phone: string}>} $corpus */
    $corpus = json_decode((string) file_get_contents(base_path('fixtures/customers/amharic-corpus.json')), true, 512, JSON_THROW_ON_ERROR);

    $byName = [];
    foreach ($corpus['customers'] as $i => $row) {
        $id = enforcedCustomer($tenantId, '2026-06-01 10:0'.$i.':00+00');
        DB::table('customers')->where('id', $id)->update(['display_name' => $row['display_name']]);
        indexCustomer($id);
        $byName[$row['display_name']] = $id;
    }

    try {
        $search = app(CustomerSearch::class);

        // First-word hit: ሰላም → ሰላም በቀለ, and nobody else.
        expect(array_column($search->search('ሰላም', $viewer)['hits'], 'id'))
            ->toBe([$byName['ሰላም በቀለ']], 'the Amharic first-word search missed (AC-07\'s corpus clause)');

        // SECOND-word hit: ተስፋዬ → አበበ ተስፋዬ — this is the tokenization
        // validation §14 names: Ethiopic names must segment on the space,
        // not index as one opaque blob.
        expect(array_column($search->search('ተስፋዬ', $viewer)['hits'], 'id'))
            ->toBe([$byName['አበበ ተስፋዬ']], 'the second-word Amharic search missed — Ethiopic tokenization is broken (§14)');
    } finally {
        dropCustomersSearchIndex();
    }
});
