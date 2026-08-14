<?php

declare(strict_types=1);

namespace App\Domain\Customers\Search;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Meilisearch\Exceptions\ApiException;
use Throwable;

/**
 * The customer search service (FR-20's search half; T-04): scope resolved
 * SERVER-SIDE from the viewer (SEC-02's one-path law — the client sends a
 * query string and nothing else), tenant selected by the physical index name,
 * hits CURATED to the searchable fields (the scope arrays never leave the
 * server; RG/blocked were never indexed to begin with).
 *
 * FS-01: Meilisearch down → a LABELED degrade to DB prefix search on
 * names/phone (the document's own fallback list), through the SAME
 * CustomerScope constraint — slower and narrower, honestly flagged
 * (`degraded: true`), never a 500, and never a scope widening. The panel is
 * untouched by construction: it reads the DB, not this index.
 */
final class CustomerSearch
{
    private const LIMIT = 20;

    public function __construct(
        private readonly CustomerSearchIndex $index,
        private readonly CustomerScope $scope,
    ) {}

    /**
     * @return array{degraded: bool, hits: list<array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>}>}
     */
    public function search(string $query, User $viewer): array
    {
        $signature = $this->scope->signature($viewer);
        if ($signature === 'none') {
            return ['degraded' => false, 'hits' => []]; // fail-closed, no round-trip
        }

        try {
            $client = $this->index->client();

            try {
                /** @var array{hits: list<array<string, mixed>>} $result */
                $result = $client->index($this->index->customersIndexName())->rawSearch($query, [
                    'filter' => $this->scope->meiliFilter($signature),
                    'limit' => self::LIMIT,
                ]);
            } catch (ApiException $e) {
                if ($e->errorCode === 'index_not_found') {
                    return ['degraded' => false, 'hits' => []]; // cold tenant — zero hits, not an outage
                }

                throw $e;
            }

            return ['degraded' => false, 'hits' => array_map($this->curate(...), $result['hits'])];
        } catch (Throwable) {
            return ['degraded' => true, 'hits' => $this->prefixSearch($query, $signature)];
        }
    }

    /**
     * FS-01's fallback: DB prefix search on names/phone, same scope brain.
     *
     * @return list<array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>}>
     */
    private function prefixSearch(string $query, string $signature): array
    {
        $prefix = addcslashes($query, '%_\\').'%';

        $base = DB::table('customers')->whereNull('merged_into_id')
            ->where(function (\Illuminate\Database\Query\Builder $where) use ($prefix): void {
                $where->where('display_name', 'ilike', $prefix)
                    ->orWhereExists(function (\Illuminate\Database\Query\Builder $sub) use ($prefix): void {
                        $sub->from('customer_identities')
                            ->whereColumn('customer_identities.customer_id', 'customers.id')
                            ->where('customer_identities.type', 'phone')
                            ->where('customer_identities.value', 'like', $prefix);
                    });
            });

        /** @var list<object{id: string, display_name: ?string}> $rows */
        $rows = $this->scope->constrainQuery($base, $signature)
            ->orderBy('customers.id')->limit(self::LIMIT)
            ->get(['customers.id', 'customers.display_name'])->all();

        return array_map(static fn (object $row): array => [
            'id' => $row->id,
            'display_name' => $row->display_name,
            'usernames' => [],
            'phones' => [],
            'emails' => [],
        ], $rows);
    }

    /**
     * The curated hit allowlist (the PII floor: exactly the searchable
     * fields; scope arrays and anything else stay server-side).
     *
     * @param  array<string, mixed>  $hit
     * @return array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>}
     */
    private function curate(array $hit): array
    {
        /** @var list<string> $usernames */
        $usernames = is_array($hit['usernames'] ?? null) ? array_values($hit['usernames']) : [];
        /** @var list<string> $phones */
        $phones = is_array($hit['phones'] ?? null) ? array_values($hit['phones']) : [];
        /** @var list<string> $emails */
        $emails = is_array($hit['emails'] ?? null) ? array_values($hit['emails']) : [];

        return [
            'id' => is_string($hit['id'] ?? null) ? $hit['id'] : '',
            'display_name' => is_string($hit['display_name'] ?? null) ? $hit['display_name'] : null,
            'usernames' => $usernames,
            'phones' => $phones,
            'emails' => $emails,
        ];
    }
}
