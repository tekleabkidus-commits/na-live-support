<?php

declare(strict_types=1);

use App\Domain\Inbox\Search\SearchIndex;
use App\Domain\Inbox\Search\SearchProxy;
use App\Jobs\Inbox\IndexSearchDocuments;

/*
|--------------------------------------------------------------------------
| AC-09 / SEC-02 / T-04 (S07 step 6) — the scope-injection proofs, EXECUTED
| BEFORE search serves real data (the plan's ordered minimum; the Doc 03
| AC-03 reuse). RLS does not reach a search index: BOTH tenancy layers here
| are code, and both are proven — the physical per-tenant index (sabotage
| #2's target) and the server-injected Doc 03 scope filter.
|--------------------------------------------------------------------------
*/

function indexTicketFor(string $ticketId, ?string $messageId = null): void
{
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    (new IndexSearchDocuments($tenantId, $ticketId, $messageId))->handle(app(SearchIndex::class));
}

function dropTenantIndexes(): void
{
    $search = app(SearchIndex::class);
    try {
        $client = $search->client();
        foreach ([$search->ticketsIndexName(), $search->messagesIndexName()] as $name) {
            $task = $client->deleteIndex($name);
            $client->waitForTask($task['taskUid']);
        }
    } catch (Throwable) {
        // absent index on a cold tenant — nothing to drop
    }
}

it('AC-09 layer 1: tenant A\'s search NEVER returns tenant B\'s indexed documents — the physical index is tenant-derived, server-side (sabotage #2 goes red here)', function (): void {
    // Tenant B, with a REAL indexed ticket whose subject is distinctive.
    [$tenantB, $convB, $ticketB] = ticketFixture('open');
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantB->identifier(), 'ticket_id' => $ticketB->identifier(),
        'conversation_id' => $convB->identifier(), 'customer_id' => $ticketB->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    asTenant($tenantB, function () use ($ticketB): void {
        Illuminate\Support\Facades\DB::table('ticket_list_items')->where('ticket_id', $ticketB->identifier())
            ->update(['subject' => 'zebra-withdrawal-9911']);
        indexTicketFor($ticketB->identifier());
    });

    // Tenant A searches the SAME distinctive term.
    [$tenantA, $convA, $ticketA] = ticketFixture('open');
    $viewerA = inboxScopedActor('All', 'S07 Search XT');

    try {
        $result = app(SearchProxy::class)->search('zebra-withdrawal-9911', $viewerA);

        expect($result['unavailable'])->toBeFalse('tenant A\'s search degraded instead of answering — the isolation claim below would be vacuous');
        expect($result['tickets'])->toBe([], 'CROSS-TENANT SEARCH HIT: tenant B\'s document surfaced in tenant A\'s results (N-01 at the index layer)');

        // Positive control: tenant B FINDS its own document — the empty
        // result above is isolation, not a broken index.
        $hitIds = asTenant($tenantB, function (): array {
            $viewerB = inboxScopedActor('All', 'S07 Search B');

            return array_column(app(SearchProxy::class)->search('zebra-withdrawal-9911', $viewerB)['tickets'], 'id');
        });
        expect(in_array($ticketB->identifier(), $hitIds, true))->toBeTrue('the positive control failed — nothing was indexed at all');
    } finally {
        dropTenantIndexes();
        asTenant($tenantB, fn () => dropTenantIndexes());
    }
});

it('AC-09 layer 2: the Own-scope filter is INJECTED server-side — no out-of-scope hit ever returns, in tickets or messages; All-scope is the positive control', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new'); // unassigned
    $tenantId = $tenant->identifier();
    $own = inboxScopedActor('Own', 'S07 Search Own');
    $all = inboxScopedActor('All', 'S07 Search All');

    $apply = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $apply($ticket, $conversation);

    $cMine = anotherConversation($tenantId, 'search-mine');
    [$tMine] = inboxTicketRow($tenantId, $cMine, 'open', ['assignee_id' => $own->identifier()]);
    $apply($tMine, $cMine);

    try {
        Illuminate\Support\Facades\DB::table('ticket_list_items')
            ->whereIn('ticket_id', [$ticket->identifier(), $tMine->identifier()])
            ->update(['subject' => 'quagga-payout-delay']);
        // A message body on the OUT-OF-SCOPE (unassigned) ticket.
        $messageId = reopenCustomerMessage($tenantId, $conversation->identifier());
        Illuminate\Support\Facades\DB::table('messages')->where('id', $messageId)->update(['body' => 'quagga payout still missing']);

        indexTicketFor($ticket->identifier(), $messageId);
        indexTicketFor($tMine->identifier());

        // The Own-scope viewer: exactly THEIR ticket, zero message hits (the
        // only matching body is on the unassigned ticket, outside Own scope).
        $mine = app(SearchProxy::class)->search('quagga', $own);
        expect(array_column($mine['tickets'], 'id'))->toBe([$tMine->identifier()], 'an out-of-scope ticket hit returned — the injected filter is gone (AC-09)')
            ->and($mine['messages'])->toBe([], 'an out-of-scope MESSAGE hit returned — the messages index filter is gone');

        // Positive control: All-scope sees both tickets AND the message hit.
        $wide = app(SearchProxy::class)->search('quagga', $all);
        expect(count($wide['tickets']))->toBe(2)
            ->and(count($wide['messages']))->toBe(1);
    } finally {
        dropTenantIndexes();
    }
});

it('SEC-02: the HTTP door accepts a QUERY STRING and nothing else — a client-sent filter/scope parameter changes NOTHING (there is no code path for it)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    activateTenant($tenant);
    $tenantId = $tenant->identifier();
    $own = inboxScopedActor('Own', 'S07 Search HTTP Own');

    asTenant($tenant, function () use ($tenantId, $ticket, $conversation): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
            'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
        Illuminate\Support\Facades\DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())
            ->update(['subject' => 'okapi-bonus-claim']);
        indexTicketFor($ticket->identifier());
    });

    try {
        // The unassigned ticket is outside Own scope. The attacker-shaped
        // request smuggles every plausible scope-widening parameter — and
        // gets the same empty answer, because the proxy reads NONE of them.
        $response = $this->actingAs($own, 'staff')->getJson(
            'https://localhost/inbox/search?q=okapi&filter=assignee_id%20IS%20NULL&scope=all&index=t_other&department_id=*'
        );
        $response->assertOk();
        expect($response->json('tickets'))->toBe([], 'a client-sent parameter widened search scope — SEC-02\'s one-path contract is broken');
    } finally {
        asTenant($tenant, fn () => dropTenantIndexes());
    }
});

it('FS-03: Meilisearch down → inline "unavailable", never a 500 — and the queued indexer job FAILS (retry/dead-letter is the catch-up path)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $viewer = inboxScopedActor('All', 'S07 Search FS3');

    // The row exists BEFORE the outage (the projector ran while healthy —
    // under the sync driver its dispatched indexer also runs inline here).
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));

    // The sabotage-#11 idiom: a dead port, not a mock.
    config()->set('services.meilisearch.host', 'http://127.0.0.1:59999');

    $result = app(SearchProxy::class)->search('anything', $viewer);
    expect($result['unavailable'])->toBeTrue()
        ->and($result['tickets'])->toBe([])
        ->and($result['messages'])->toBe([]);

    // The indexer against the dead port THROWS (so tries ×3 → dead-letter →
    // re-drive can catch up) — an indexer that swallowed the outage would
    // silently drop documents forever.
    expect(fn () => indexTicketFor($ticket->identifier()))->toThrow(Meilisearch\Exceptions\CommunicationException::class);
});
