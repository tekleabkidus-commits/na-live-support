<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * The view membership store (Doc 07 T-01): one Redis sorted set per
 * (tenant, view key), members = ticket ids, scores = INTEGER MICROSECONDS of
 * the view's sort field (R-23: never fractional seconds — Redis scores are
 * IEEE-754 doubles; integer µs is exact below 2^53; the residual
 * same-microsecond tiebreak is lexicographic member order — arbitrary but
 * TOTAL, documented here as the family principle requires).
 *
 * NEVER THE SOURCE OF TRUTH (C-07): redis-volatile, mandatory TTL refreshed
 * on every write; the invalidation event is FS-02's rebuild — an evicted or
 * lost set rebuilds from the canonical rows (SystemViews' direct queries),
 * and NFR-07's checker alerts on drift long before an agent would.
 */
final class ViewZsets
{
    private const CONNECTION = 'volatile';

    /** C-07's mandatory TTL: long-lived read model, refreshed on write; loss = rebuild. */
    private const TTL_SECONDS = 604_800; // 7 days

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    /** t:{tenant}:view:{key} — C-07's tenant prefix on every cache key. */
    public function key(string $viewKey): string
    {
        return sprintf('t:%s:view:%s', $this->context->tenantId(), $viewKey);
    }

    /** @return bool TRUE when the member was NEW (T-02 op=add), false on a rescore (op=patch). */
    public function add(string $viewKey, string $ticketId, int $scoreMicros): bool
    {
        $key = $this->key($viewKey);
        /** @var int|string $added ZADD's return: number of NEW members */
        $added = $this->redis->connection(self::CONNECTION)->command('zadd', [$key, $scoreMicros, $ticketId]);
        $this->redis->connection(self::CONNECTION)->command('expire', [$key, self::TTL_SECONDS]);

        return (int) $added === 1;
    }

    /** @return bool TRUE when a member was actually removed (T-02 op=remove pushes only then). */
    public function remove(string $viewKey, string $ticketId): bool
    {
        /** @var int|string $removed */
        $removed = $this->redis->connection(self::CONNECTION)->command('zrem', [$this->key($viewKey), $ticketId]);

        return (int) $removed === 1;
    }

    public function count(string $viewKey): int
    {
        /** @var int|string $count phpredis returns int; predis may return string */
        $count = $this->redis->connection(self::CONNECTION)->command('zcard', [$this->key($viewKey)]);

        return (int) $count;
    }

    /** @return list<string> ticket ids in ascending score order */
    public function ids(string $viewKey, int $offset = 0, int $limit = 50, bool $descending = false): array
    {
        $command = $descending ? 'zrevrange' : 'zrange';

        /** @var list<string> $ids */
        $ids = $this->redis->connection(self::CONNECTION)
            ->command($command, [$this->key($viewKey), $offset, $offset + $limit - 1]);

        return $ids;
    }

    /**
     * One CURSOR page (C-05 — FR-12's infinite scroll): members strictly
     * AFTER the cursor position in the view's order, WITH their scores (the
     * next cursor is the last row's own (score, id)).
     *
     * The cursor is (score, id). Redis orders same-score members
     * lexicographically (the R-23 documented tiebreak), so the page fetches
     * by score bound INCLUSIVE and drops members at the cursor score up to
     * and including the cursor id — exact resumption without trusting
     * offsets, which live mutation invalidates (the reason C-05 bans them).
     *
     * @return list<array{id: string, score: int}>
     */
    public function cursorPage(string $viewKey, ?int $cursorScore, ?string $cursorId, int $limit, bool $descending): array
    {
        $key = $this->key($viewKey);
        $connection = $this->redis->connection(self::CONNECTION);

        if ($cursorScore === null) {
            $command = $descending ? 'zrevrange' : 'zrange';
            /** @var array<string, string|int|float>|list<string|int|float> $flat */
            $flat = $connection->command($command, [$key, 0, $limit - 1, true]); // phpredis: 4th arg = withscores
        } else {
            // Inclusive bound at the cursor score: same-score members after
            // the cursor id must not be skipped. The over-fetch margin covers
            // members sharing the cursor's exact microsecond (rare; R-23).
            $fetch = $limit + 100;
            $options = ['withscores' => true, 'limit' => [0, $fetch]];
            /** @var array<string, string|int|float>|list<string|int|float> $flat */
            $flat = $descending
                ? $connection->command('zrevrangebyscore', [$key, (string) $cursorScore, '-inf', $options])
                : $connection->command('zrangebyscore', [$key, (string) $cursorScore, '+inf', $options]);
        }

        // phpredis answers WITHSCORES as an ordered map {member: score};
        // predis answers a flat [member, score, …] list. Normalize to pairs.
        /** @var list<array{0: string, 1: int}> $pairs */
        $pairs = [];
        $flatValues = array_values((array) $flat);
        $isMap = array_keys((array) $flat) !== range(0, count($flatValues) - 1);
        if ($isMap) {
            foreach ((array) $flat as $member => $score) {
                $pairs[] = [(string) $member, (int) $score];
            }
        } else {
            for ($i = 0; $i + 1 < count($flatValues); $i += 2) {
                $pairs[] = [(string) $flatValues[$i], (int) $flatValues[$i + 1]];
            }
        }

        /** @var list<array{id: string, score: int}> $page */
        $page = [];
        $pastCursor = $cursorScore === null;
        foreach ($pairs as [$id, $score]) {
            if (! $pastCursor) {
                if ($score !== $cursorScore) {
                    $pastCursor = true; // beyond the cursor's score band
                } elseif ($cursorId === null || ($descending ? strcmp($id, $cursorId) < 0 : strcmp($id, $cursorId) > 0)) {
                    // Same-score member PAST the cursor id in traversal order
                    // (lexical compare, not equality — a cursor member removed
                    // between pages must not swallow its score band).
                    $pastCursor = true;
                } else {
                    continue; // at or before the cursor id within the band
                }
            }

            $page[] = ['id' => $id, 'score' => $score];
            if (count($page) === $limit) {
                break;
            }
        }

        return $page;
    }

    /** @return list<string> every member (rebuild/checker use, bounded by view size) */
    public function allIds(string $viewKey): array
    {
        /** @var list<string> $ids */
        $ids = $this->redis->connection(self::CONNECTION)->command('zrange', [$this->key($viewKey), 0, -1]);

        return $ids;
    }

    public function flush(string $viewKey): void
    {
        $this->redis->connection(self::CONNECTION)->command('del', [$this->key($viewKey)]);
    }

    /** Integer microseconds since epoch from an ISO/SQL timestamp string (R-23). */
    public static function toMicros(string $timestamp): int
    {
        $dt = new \DateTimeImmutable($timestamp);

        return ((int) $dt->format('U')) * 1_000_000 + (int) $dt->format('u');
    }
}
