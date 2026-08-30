<?php

declare(strict_types=1);

namespace App\Domain\Routing\Waiting;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Doc 11 T-02 / T-03 / FS-01 / FS-02 — the per-queue WAITING SETS: Redis ZSETs on
 * redis-durable (timers-class state, Doc 01's stack table), score = effective priority + age
 * function, MIRRORING DB truth (waiting = open-family, unassigned, queue_id = this queue) and
 * rebuildable from it. Redis is never the truth.
 *
 * SCORE (ascending; the HEAD is the lowest): starved tickets sort first (NFR-06 — the cap is
 * a promotion outside the score), then by boost score descending, then FIFO by queued_at.
 *   score = tier * 1e15 + (10_000 - boost) * 1e10 + queued_at_seconds
 *   tier  = 0 when starved, 1 otherwise
 *
 * FS-02: pop is READ-THEN-COMMIT — head() reads without removing; remove() runs only after
 * a SUCCESSFUL assignment (the dispatcher's contract). A worker dying mid-pop leaves the member.
 */
final class WaitingSets
{
    private const CONNECTION = 'durable';

    public function __construct(private readonly TenantContext $context) {}

    public function key(string $queueId): string
    {
        return sprintf('t:%s:routing:q:%s', $this->context->tenantId(), $queueId);
    }

    public static function score(bool $starved, int $boostScore, int $queuedAtSeconds): int
    {
        $tier = $starved ? 0 : 1;

        return $tier * 1_000_000_000_000_000 + (10_000 - min($boostScore, 9_999)) * 10_000_000_000 + $queuedAtSeconds;
    }

    public function add(string $queueId, string $ticketId, int $score): void
    {
        Redis::connection(self::CONNECTION)->zadd($this->key($queueId), [$ticketId => $score]);
    }

    /**
     * FS-02: read the head WITHOUT removing it.
     *
     * @return list<string> up to $limit ticket ids, head first
     */
    public function head(string $queueId, int $limit = 1): array
    {
        /** @var list<string> $ids */
        $ids = Redis::connection(self::CONNECTION)->zrange($this->key($queueId), 0, $limit - 1);

        return $ids;
    }

    /** Removal ONLY after a successful assignment (or a departure from the queue). */
    public function remove(string $queueId, string $ticketId): void
    {
        Redis::connection(self::CONNECTION)->zrem($this->key($queueId), $ticketId);
    }

    public function depth(string $queueId): int
    {
        return (int) Redis::connection(self::CONNECTION)->zcard($this->key($queueId));
    }

    /** 1-based position of a ticket in its queue, null when absent (FR-19 ticket.waiting {position}). */
    public function position(string $queueId, string $ticketId): ?int
    {
        $rank = Redis::connection(self::CONNECTION)->zrank($this->key($queueId), $ticketId);

        return is_int($rank) ? $rank + 1 : null;
    }

    /**
     * FS-01: rebuild every waiting set of the BOUND tenant from DB truth — the set is
     * REPLACED (members that are no longer waiting drop out), scores recomputed by the caller's
     * scorer so the order is the engine's, never a stale one.
     *
     * @param  callable(object{id: string, queue_id: string, priority: string, reopened_count: int|string, customer_id: string, queued_at: ?string, tags: ?string}): int  $scorer
     * @return int members rebuilt
     */
    public function rebuild(callable $scorer): int
    {
        $redis = Redis::connection(self::CONNECTION);
        /** @var list<string> $queueIds */
        $queueIds = DB::table('queues')->whereNull('deleted_at')->pluck('id')->all();
        foreach ($queueIds as $queueId) {
            $redis->del($this->key($queueId));
        }
        /** @var list<object{id: string, queue_id: string, priority: string, reopened_count: int|string, customer_id: string, queued_at: ?string, tags: ?string}> $rows */
        $rows = DB::table('tickets')->whereNull('assignee_id')->whereNotNull('queue_id')
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->get(['id', 'queue_id', 'priority', 'reopened_count', 'customer_id', 'queued_at', 'tags'])->all();
        foreach ($rows as $row) {
            $this->add($row->queue_id, $row->id, $scorer($row));
        }

        return count($rows);
    }
}
