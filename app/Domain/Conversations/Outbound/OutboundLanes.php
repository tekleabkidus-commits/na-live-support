<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * FR-15/T-02's per-conversation FIFO lanes: Redis lists `lane:{conversation}` on
 * redis-durable (a lane entry is a promise to send — it must survive a restart;
 * volatile's eviction would silently drop queued sends). An index SET tracks
 * which lanes are non-empty so the worker round-robins without KEYS scans.
 *
 * The lane holds MESSAGE IDS only; all message truth stays in Postgres. A lane
 * entry whose row is missing or already terminal is popped and skipped — the
 * list is a work queue, never a source of truth (C-07's spirit on durable).
 */
final class OutboundLanes
{
    private const CONNECTION = 'durable';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function push(string $conversationId, string $messageId): void
    {
        $connection = $this->redis->connection(self::CONNECTION);

        $connection->command('rpush', [$this->laneKey($conversationId), $messageId]);
        $connection->command('sadd', [$this->indexKey(), $conversationId]);
    }

    public function peekHead(string $conversationId): ?string
    {
        $head = $this->redis->connection(self::CONNECTION)
            ->command('lindex', [$this->laneKey($conversationId), 0]);

        return is_string($head) ? $head : null;
    }

    /** Pop the head (it reached a terminal state); deindex an emptied lane. */
    public function popHead(string $conversationId): void
    {
        $connection = $this->redis->connection(self::CONNECTION);

        $connection->command('lpop', [$this->laneKey($conversationId)]);

        $length = $connection->command('llen', [$this->laneKey($conversationId)]);

        if (! is_int($length) || $length === 0) {
            $connection->command('srem', [$this->indexKey(), $conversationId]);
        }
    }

    /**
     * FR-15's lane-skip: the stuck head moves to the TAIL so the lane drains past
     * it; its own retries continue on their backoff schedule from back there.
     */
    public function demoteHead(string $conversationId): void
    {
        $connection = $this->redis->connection(self::CONNECTION);

        $head = $connection->command('lpop', [$this->laneKey($conversationId)]);

        if (is_string($head)) {
            $connection->command('rpush', [$this->laneKey($conversationId), $head]);
        }
    }

    /** @return list<string> conversation ids with pending sends, for round-robin */
    public function activeLanes(): array
    {
        $members = $this->redis->connection(self::CONNECTION)->command('smembers', [$this->indexKey()]);

        $lanes = is_array($members) ? array_values(array_filter($members, 'is_string')) : [];
        sort($lanes); // deterministic visit order; fairness comes from one-attempt-per-lane-per-cycle

        return $lanes;
    }

    private function laneKey(string $conversationId): string
    {
        return sprintf('t:%s:lane:%s', $this->context->tenantId(), $conversationId);
    }

    private function indexKey(): string
    {
        return sprintf('t:%s:lanes', $this->context->tenantId());
    }
}
