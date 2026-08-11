<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Search;

use App\Domain\Inbox\Realtime\ViewHash;
use App\Models\User;
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

        // FR-23's customer section, v1: customer-field matches from the
        // tickets index (T-04 defines two indexes; customer identity search
        // deepens with Doc 08), grouped by customer.
        $customers = [];
        foreach ($tickets['hits'] as $hit) {
            $name = $hit['customer_display_name'] ?? null;
            $username = is_string($hit['customer_username'] ?? null) ? $hit['customer_username'] : '';
            $phone = is_string($hit['customer_phone'] ?? null) ? $hit['customer_phone'] : '';
            if (is_string($name) && $name !== '' && stripos($name.' '.$username.' '.$phone, $query) !== false) {
                $customers[$name] ??= [
                    'display_name' => $name,
                    'username' => $hit['customer_username'] ?? null,
                    'ticket_ids' => [],
                ];
                $customers[$name]['ticket_ids'][] = $hit['id'];
            }
        }

        return [
            'unavailable' => false,
            'tickets' => $tickets['hits'],
            'messages' => $messages['hits'],
            'customers' => array_values($customers),
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
