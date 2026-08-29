<?php

declare(strict_types=1);

namespace App\Domain\Kb\Search;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Throwable;

/**
 * Doc 10 FR-17 / FS-02 / SEC-06 (S10 step 10): the manager's keyword search. INTERNAL articles
 * are ABSENT for a viewer without kb.view_internal (an index-side filter, the C-10 posture —
 * not a post-filter). Meilisearch down → `unavailable: true`, never a 500 and never an empty
 * "no results" lie (Doc 07 FS-03's lesson). The empty state's "create article from this query"
 * shortcut is a RENDER hint carrying the query back to the caller — nothing here stores the
 * query anywhere (SEC-06: no table, no cache, no log line carries it).
 *
 * @phpstan-type Filters array{language?: string, visibility?: string, status?: string, category_id?: string}
 */
final class StaffSearch
{
    public function __construct(private readonly KbSearchIndex $index, private readonly CapabilityMapProvider $verdicts) {}

    /**
     * @param  array{language?: string, visibility?: string, status?: string, category_id?: string}  $filters
     * @return array{unavailable: bool, hits: list<array<string, mixed>>, create_from_query: string|null}
     */
    public function search(string $query, User $viewer, array $filters = [], int $limit = 20): array
    {
        $filter = [];
        if (! $this->verdicts->mapFor($viewer)->allows('kb.view_internal')) {
            $filter[] = 'visibility = public';
        }
        foreach (['language', 'visibility', 'status', 'category_id'] as $key) {
            if (isset($filters[$key]) && preg_match('/^[a-z0-9_-]{1,40}$/', $filters[$key]) === 1) {
                $filter[] = $key.' = '.$filters[$key];
            }
        }
        try {
            $result = $this->index->client()->index($this->index->indexName())->search($query, ['filter' => $filter, 'limit' => min(max($limit, 1), 100)]);
            /** @var list<array<string, mixed>> $hits */
            $hits = $result->getHits();
        } catch (Throwable) {
            return ['unavailable' => true, 'hits' => [], 'create_from_query' => null];
        }

        return ['unavailable' => false, 'hits' => $hits, 'create_from_query' => $hits === [] && trim($query) !== '' ? $query : null];
    }
}
