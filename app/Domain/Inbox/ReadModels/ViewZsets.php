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

    public function add(string $viewKey, string $ticketId, int $scoreMicros): void
    {
        $key = $this->key($viewKey);
        $this->redis->connection(self::CONNECTION)->command('zadd', [$key, $scoreMicros, $ticketId]);
        $this->redis->connection(self::CONNECTION)->command('expire', [$key, self::TTL_SECONDS]);
    }

    public function remove(string $viewKey, string $ticketId): void
    {
        $this->redis->connection(self::CONNECTION)->command('zrem', [$this->key($viewKey), $ticketId]);
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
