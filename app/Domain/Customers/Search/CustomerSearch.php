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
     * @return array{degraded: bool, hits: list<array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>, matched: array{on: string, value: string, used_until: ?string, current_username: ?string}|null}>}
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

            return ['degraded' => false, 'hits' => $this->annotate($query, array_map($this->curate(...), $result['hits']))];
        } catch (Throwable) {
            return ['degraded' => true, 'hits' => $this->prefixSearch($query, $signature)];
        }
    }

    /**
     * G04's drawn explanation ("Matched an earlier Telegram name @x, used
     * until <date>. Now @y.") — each hit says WHICH searchable value matched,
     * and for a historical username, when it stopped being current. One
     * batched identity read for the whole page of hits; the matched value is
     * always one the client already received (the searchable-fields floor
     * holds — the only addition is the history stamp on a value that matched).
     *
     * @param  list<array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>}>  $hits
     * @return list<array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>, matched: array{on: string, value: string, used_until: ?string, current_username: ?string}|null}>
     */
    private function annotate(string $query, array $hits): array
    {
        $ids = array_values(array_filter(array_map(static fn (array $hit): string => $hit['id'], $hits)));

        /** @var array<string, array{current: ?string, history: array<string, ?string>}> $usernameFacts */
        $usernameFacts = [];
        if ($ids !== []) {
            /** @var list<object{customer_id: string, metadata: ?string}> $rows */
            $rows = DB::table('customer_identities')->whereIn('customer_id', $ids)
                ->where('type', 'telegram')->get(['customer_id', 'metadata'])->all();
            foreach ($rows as $row) {
                /** @var array<string, mixed> $meta */
                $meta = $row->metadata !== null ? (array) json_decode($row->metadata, true) : [];
                $history = [];
                foreach (is_array($meta['history'] ?? null) ? $meta['history'] : [] as $entry) {
                    if (is_array($entry) && is_string($entry['old'] ?? null)) {
                        $history[$entry['old']] = is_string($entry['changed_at'] ?? null) ? $entry['changed_at'] : null;
                    }
                }
                $usernameFacts[$row->customer_id] = [
                    'current' => is_string($meta['username'] ?? null) ? $meta['username'] : null,
                    'history' => $history,
                ];
            }
        }

        return array_map(function (array $hit) use ($query, $usernameFacts): array {
            $hit['matched'] = $this->matchedOn($query, $hit, $usernameFacts[$hit['id']] ?? ['current' => null, 'history' => []]);

            return $hit;
        }, $hits);
    }

    /**
     * @param  array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>}  $hit
     * @param  array{current: ?string, history: array<string, ?string>}  $facts
     * @return array{on: string, value: string, used_until: ?string, current_username: ?string}|null
     */
    private function matchedOn(string $query, array $hit, array $facts): ?array
    {
        $contains = static fn (?string $value): bool => is_string($value) && $value !== ''
            && mb_stripos($value, trim($query)) !== false;

        if ($contains($hit['display_name'])) {
            return ['on' => 'name', 'value' => (string) $hit['display_name'], 'used_until' => null, 'current_username' => null];
        }
        foreach ($hit['usernames'] as $username) {
            if (! $contains($username)) {
                continue;
            }
            if ($username !== $facts['current'] && array_key_exists($username, $facts['history'])) {
                return [
                    'on' => 'username_history', 'value' => $username,
                    'used_until' => $facts['history'][$username], 'current_username' => $facts['current'],
                ];
            }

            return ['on' => 'username', 'value' => $username, 'used_until' => null, 'current_username' => null];
        }
        // Phones match as dialled: compare digits to digits (§14 — an agent
        // types what the caller says; the store holds E.164).
        $queryDigits = preg_replace('/\D+/', '', $query) ?? '';
        if ($queryDigits !== '') {
            foreach ($hit['phones'] as $phone) {
                $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
                if ($phoneDigits !== '' && str_contains($phoneDigits, $queryDigits)) {
                    return ['on' => 'phone', 'value' => $phone, 'used_until' => null, 'current_username' => null];
                }
            }
        }
        foreach ($hit['emails'] as $email) {
            if ($contains($email)) {
                return ['on' => 'email', 'value' => $email, 'used_until' => null, 'current_username' => null];
            }
        }

        return null; // a tokenized/typo match the substring probe can't name — the row renders unannotated
    }

    /**
     * FS-01's fallback: DB prefix search on names/phone, same scope brain.
     *
     * @return list<array{id: string, display_name: ?string, usernames: list<string>, phones: list<string>, emails: list<string>, matched: array{on: string, value: string, used_until: ?string, current_username: ?string}|null}>
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

        // G06: a degraded hit still says how it was found — the fallback
        // searches exactly two fields, so the answer is name-or-phone.
        $nameMatch = static fn (?string $name): bool => is_string($name)
            && mb_stripos($name, trim($query)) === 0;

        return array_map(static fn (object $row): array => [
            'id' => $row->id,
            'display_name' => $row->display_name,
            'usernames' => [],
            'phones' => [],
            'emails' => [],
            'matched' => [
                'on' => $nameMatch($row->display_name) ? 'name' : 'phone',
                'value' => $nameMatch($row->display_name) ? (string) $row->display_name : '',
                'used_until' => null,
                'current_username' => null,
            ],
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
