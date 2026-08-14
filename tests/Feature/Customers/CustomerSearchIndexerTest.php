<?php

declare(strict_types=1);

use App\Domain\Customers\Search\CustomerSearch;
use App\Jobs\Customers\CustomerSearchIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 T-04 (S08 step 8) — the durable indexer: fed from FR-22 events,
| scope arrays refreshed by the ticket family, tombstones removed, C-09 by
| construction (every delivery rewrites from truth).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** Seed the event row the relay would have written (catalog shape). */
function searchIndexEvent(string $tenantId, string $name, array $payload): string
{
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => $name, 'schema_version' => 1,
        'payload' => json_encode(['tenant_id' => $tenantId] + $payload, JSON_THROW_ON_ERROR),
    ]);

    return $eventId;
}

it('indexes a customer from a customer.created delivery — and a REPLAY rewrites the same document, never a duplicate (C-09)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $viewer = customersScopedActor('All', 'S08 Indexer All');

    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['display_name' => 'Nyala Created']);

    $eventId = searchIndexEvent($tenantId, 'customer.created', ['customer_id' => $customerId, 'created_source' => 'staff']);
    (new CustomerSearchIndexer($eventId))->handle(app(App\Domain\Customers\Search\CustomerSearchDocuments::class));
    // The C-09 clause, exercised as the relay would: the SAME event id again.
    (new CustomerSearchIndexer($eventId))->handle(app(App\Domain\Customers\Search\CustomerSearchDocuments::class));

    try {
        expect(array_column(app(CustomerSearch::class)->search('Nyala', $viewer)['hits'], 'id'))->toBe([$customerId]);
    } finally {
        dropCustomersSearchIndex();
    }
});

it('REFRESHES the scope arrays on ticket.assigned — assignment grants Own-scope search visibility, reassignment away REVOKES it (the scope-leak clause)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $own = customersScopedActor('Own', 'S08 Indexer Own');

    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['display_name' => 'Gerenuk Scoped']);
    indexCustomer($customerId); // no tickets yet — empty scope arrays

    try {
        $search = app(CustomerSearch::class);
        expect($search->search('Gerenuk', $own)['hits'])->toBe([], 'visible before any assignment — Own scope started open');

        // The ticket lands assigned to the viewer; the DELIVERY refreshes.
        $conversation = anotherConversation($tenantId, 'idx-scope', $customerId);
        [$ticket] = inboxTicketRow($tenantId, $conversation, 'open', ['assignee_id' => $own->identifier()]);
        $assigned = searchIndexEvent($tenantId, 'ticket.assigned', [
            'ticket_id' => $ticket->identifier(), 'conversation_id' => $conversation->identifier(),
            'old_assignee_id' => null, 'new_assignee_id' => $own->identifier(), 'by' => null,
        ]);
        (new CustomerSearchIndexer($assigned))->handle(app(App\Domain\Customers\Search\CustomerSearchDocuments::class));

        expect(array_column($search->search('Gerenuk', $own)['hits'], 'id'))
            ->toBe([$customerId], 'assignment did not grant search visibility — the scope refresh is dead');

        // Reassigned AWAY: the array must shrink, or the ex-assignee keeps
        // finding the customer forever — the leak this consumer exists for.
        DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => null]);
        $unassigned = searchIndexEvent($tenantId, 'ticket.assigned', [
            'ticket_id' => $ticket->identifier(), 'conversation_id' => $conversation->identifier(),
            'old_assignee_id' => $own->identifier(), 'new_assignee_id' => null, 'by' => null,
        ]);
        (new CustomerSearchIndexer($unassigned))->handle(app(App\Domain\Customers\Search\CustomerSearchDocuments::class));

        expect($search->search('Gerenuk', $own)['hits'])->toBe([], 'the ex-assignee still finds the customer — stale scope arrays are a scope LEAK');
    } finally {
        dropCustomersSearchIndex();
    }
});

it('REMOVES the tombstone on customer.merged (BR-05: tombstones redirect, they never surface as search results) and keeps the survivor', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $viewer = customersScopedActor('All', 'S08 Indexer Merge');

    $survivor = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $survivor)->update(['display_name' => 'Bongo Survivor']);
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    DB::table('customers')->where('id', $merged)->update(['display_name' => 'Bongo Merged']);
    indexCustomer($survivor);
    indexCustomer($merged);

    // The merge's own column write is the truth the indexer re-reads.
    DB::table('customers')->where('id', $merged)->update(['merged_into_id' => $survivor]);
    $eventId = searchIndexEvent($tenantId, 'customer.merged', [
        'survivor_id' => $survivor, 'merged_id' => $merged, 'snapshot_id' => (string) Str::uuid7(),
    ]);
    (new CustomerSearchIndexer($eventId))->handle(app(App\Domain\Customers\Search\CustomerSearchDocuments::class));

    try {
        expect(array_column(app(CustomerSearch::class)->search('Bongo', $viewer)['hits'], 'id'))
            ->toBe([$survivor], 'the tombstone still surfaces in search — BR-05\'s redirect-only law is broken at the index');
    } finally {
        dropCustomersSearchIndex();
    }
});
