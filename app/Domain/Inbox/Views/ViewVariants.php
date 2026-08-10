<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Views;

use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * A-30's per-scope ZSET variants, under its four countersigned conditions:
 *
 *  1. LAZY: a variant materializes on FIRST READ (ensure()), never ahead of
 *     demand — a set never built cannot drift and costs nothing.
 *  2. C-07: variants live on redis-volatile with the same mandatory TTL as
 *     the base sets; each build re-registers its signature in the per-view
 *     variant index (a redis SET) the projector patches from.
 *  3. FS-02 covers variant loss: an evicted variant simply re-materializes on
 *     the next read through this same ensure() path — rebuild and lazy build
 *     are ONE code path, so they cannot disagree.
 *  4. The EC-04 fixture (PerViewerCountsTest) proves two scopes → two counts
 *     with zero out-of-scope rows.
 *
 * Scope signatures come from ViewHash's grammar (own:{id} | team:{ids} |
 * dept:{ids}); 'all' IS the base set and never materializes a variant;
 * 'none' materializes an empty set (fail-closed, NFR-04's posture).
 */
final class ViewVariants
{
    private const CONNECTION = 'volatile';

    private const TTL_SECONDS = 604_800;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly ViewZsets $zsets,
    ) {}

    /** The per-view variant index the projector patches from. */
    public function indexKey(string $baseKey): string
    {
        return sprintf('t:%s:view:variants:%s', $this->context->tenantId(), $baseKey);
    }

    /** @return list<string> materialized scope signatures for a base view */
    public function signatures(string $baseKey): array
    {
        /** @var list<string> $sigs */
        $sigs = $this->redis->connection(self::CONNECTION)->command('smembers', [$this->indexKey($baseKey)]);

        return $sigs;
    }

    /**
     * Materialize (or refresh-register) a scoped variant; returns its ZSET key.
     * $baseKey is a system view name or 'saved:{id}'.
     *
     * @param  array<string, mixed>|null  $savedFilters  the saved view's filter set when $baseKey is saved:{id}
     */
    public function ensure(string $baseKey, string $signature, ?array $savedFilters = null): string
    {
        $variantKey = $baseKey.':scope:'.$signature;

        /** @var int|string $exists */
        $exists = $this->redis->connection(self::CONNECTION)
            ->command('exists', [$this->zsets->key($variantKey)]);
        if ((int) $exists === 1) {
            return $variantKey;
        }

        $this->rebuild($baseKey, $signature, $savedFilters);

        return $variantKey;
    }

    /**
     * Build a variant from the canonical rows — the SAME path lazy build,
     * eviction recovery and FS-02's rebuild all take (condition 3).
     *
     * @param  array<string, mixed>|null  $savedFilters
     */
    public function rebuild(string $baseKey, string $signature, ?array $savedFilters = null): void
    {
        $variantKey = $baseKey.':scope:'.$signature;
        $this->zsets->flush($variantKey);

        if ($signature !== 'none') {
            foreach ($this->scopedRows($baseKey, $signature, $savedFilters) as $row) {
                $this->zsets->add($variantKey, (string) $row->id, ViewZsets::toMicros((string) $row->sort_at));
            }
        } else {
            // Fail-closed: an empty set EXISTS (a marker member would lie to
            // ZCARD; instead the index registration below is the existence
            // record and reads treat missing-after-register as empty).
        }

        $connection = $this->redis->connection(self::CONNECTION);
        $connection->command('sadd', [$this->indexKey($baseKey), $signature]);
        $connection->command('expire', [$this->indexKey($baseKey), self::TTL_SECONDS]);
    }

    /**
     * The projector's per-row predicate for a scope signature (must agree with scopedRows' SQL).
     *
     * @param  object{assignee_id: string|null, department_id: string|null}  $row
     */
    public static function signatureMatches(string $signature, object $row): bool
    {
        if ($signature === 'all') {
            return true;
        }
        if (str_starts_with($signature, 'own:')) {
            return $row->assignee_id === substr($signature, 4);
        }
        if (str_starts_with($signature, 'team:') || str_starts_with($signature, 'dept:')) {
            $ids = array_filter(explode(',', substr($signature, 5)));

            return $row->department_id !== null && in_array($row->department_id, $ids, true);
        }

        return false; // 'none' and unknown grammar: fail closed
    }

    /**
     * @param  array<string, mixed>|null  $savedFilters
     * @return list<object{id: string, sort_at: string}>
     */
    private function scopedRows(string $baseKey, string $signature, ?array $savedFilters): array
    {
        if (str_starts_with($baseKey, 'saved:')) {
            $query = DB::table('ticket_list_items as li')
                ->selectRaw('li.ticket_id AS id, li.last_activity_at AS sort_at');
            SavedViewFilters::sql($query, $savedFilters ?? []);
        } else {
            $query = SystemViews::directQuery($baseKey);
        }

        // The scope conditions — the SQL twin of signatureMatches().
        if (str_starts_with($signature, 'own:')) {
            $query->where(str_starts_with($baseKey, 'saved:') ? 'li.assignee_id' : 't.assignee_id', substr($signature, 4));
        } elseif (str_starts_with($signature, 'team:') || str_starts_with($signature, 'dept:')) {
            $ids = array_filter(explode(',', substr($signature, 5)));
            $column = str_starts_with($baseKey, 'saved:') ? 'li.department_id' : 't.department_id';
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
        }

        /** @var list<object{id: string, sort_at: string}> $rows */
        $rows = $query->get()->all();

        return $rows;
    }
}
