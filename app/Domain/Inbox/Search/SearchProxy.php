<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Search;

use App\Domain\Customers\Search\CustomerSearch;
use App\Domain\Inbox\Realtime\ViewHash;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Meilisearch\Exceptions\ApiException;
use Throwable;

/**
 * SEC-02: THE ONLY SEARCH PATH. The client sends a QUERY STRING and nothing
 * else — the tenant selects the physical index (layer 1) and the viewer's
 * Doc 03 scope, resolved SERVER-SIDE from the same signature grammar the
 * view channels use, injects the filter (layer 2). No Meilisearch key or
 * host is client-reachable; a request parameter naming a filter is ignored
 * by construction (the signature is the only filter source).
 *
 * Scope → filter (the ViewHash grammar, one derivation for channels, lists
 * and search — BR-03 has one brain):
 *   all          no filter
 *   own:{id}     assignee_id = {id}
 *   team/dept:{ids}   department_id IN [ids]
 *   none         the EMPTY result set, fail-closed (NFR-04's posture)
 *
 * FS-03: Meilisearch down → {unavailable: true} inline — the palette's
 * Actions/Views sections keep working client-side; nothing throws to the
 * agent; the indexer's queued jobs retry/dead-letter and catch up.
 */
final class SearchProxy
{
    public function __construct(
        private readonly SearchIndex $index,
        private readonly ViewHash $hashes,
        private readonly CustomerSearch $customerSearch,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    /**
     * @return array{unavailable: bool, tickets: list<array<string, mixed>>, messages: list<array<string, mixed>>, customers: list<array<string, mixed>>}
     */
    public function search(string $query, User $viewer): array
    {
        $signature = $this->hashes->scopeSignature($viewer);

        if ($signature === 'none') {
            return ['unavailable' => false, 'tickets' => [], 'messages' => [], 'customers' => []];
        }

        $filter = $this->filterFor($signature);

        // FR-23's customer section — DEEPENED onto Doc 08's customers_index
        // (T-04; S08 step 8, escalation #3 part 1). Ruling D2 (a BEHAVIOR
        // change, recorded): the deepened section reads the customers index
        // directly rather than deriving names from ticket documents the
        // viewer could already see, so it is gated on customers.view —
        // absence for non-holders, the house posture. CustomerSearch injects
        // the CUSTOMERS-module scope itself (its own signature — roles are
        // editable, the modules may diverge).
        $customers = [];
        if ($this->verdicts->mapFor($viewer)->allows('customers.view')) {
            $customerResult = $this->customerSearch->search($query, $viewer);
            if ($customerResult['degraded']) {
                // Ruling D1: a degraded answer routes into the palette's OWN
                // unavailable posture — an empty list would read "no
                // customers match" when the truth is "search is down"
                // (FS-03's lesson, inverted). FS-01's DB degrade belongs to
                // the DIRECTORY door, never forked into a second outage
                // behavior here.
                return ['unavailable' => true, 'tickets' => [], 'messages' => [], 'customers' => []];
            }
            $customers = array_map(
                static fn (array $hit): array => [
                    'id' => $hit['id'],
                    'display_name' => $hit['display_name'],
                    'username' => $hit['usernames'][0] ?? null,
                ],
                $customerResult['hits'],
            );
        }

        try {
            $client = $this->index->client();

            // Each section independently: a tenant may hold tickets but no
            // indexed message yet (or vice versa) — an ABSENT index answers
            // zero hits for ITS section, honestly (not an outage, not a
            // blackout of the other section).
            $tickets = $this->sectionSearch($client, $this->index->ticketsIndexName(), $query, $filter);
            $messages = $this->sectionSearch($client, $this->index->messagesIndexName(), $query, $filter);
        } catch (Throwable) {
            // FS-03: down, timeout — the palette degrades to "search
            // unavailable" inline; never a 500.
            return ['unavailable' => true, 'tickets' => [], 'messages' => [], 'customers' => []];
        }

        return [
            'unavailable' => false,
            'tickets' => $tickets['hits'],
            'messages' => $messages['hits'],
            'customers' => $customers,
        ];
    }

    /**
     * @return array{hits: list<array<string, mixed>>}
     */
    private function sectionSearch(\Meilisearch\Client $client, string $indexName, string $query, ?string $filter): array
    {
        try {
            /** @var array{hits: list<array<string, mixed>>} $result */
            $result = $client->index($indexName)->rawSearch($query, ['filter' => $filter, 'limit' => 20]);

            return $result;
        } catch (ApiException $e) {
            if ($e->errorCode === 'index_not_found') {
                return ['hits' => []]; // cold section — zero hits, not an outage
            }

            throw $e;
        }
    }

    private function filterFor(string $signature): ?string
    {
        if ($signature === 'all') {
            return null;
        }
        if (str_starts_with($signature, 'own:')) {
            return sprintf("assignee_id = '%s'", substr($signature, 4));
        }

        // team:/dept: — department membership (ids are server-resolved uuids).
        $ids = array_filter(explode(',', substr($signature, 5)));
        if ($ids === []) {
            return "department_id = 'none-matches-nothing'"; // fail-closed empty set
        }

        return sprintf('department_id IN [%s]', implode(', ', array_map(
            static fn (string $id): string => sprintf("'%s'", $id),
            $ids,
        )));
    }
}
