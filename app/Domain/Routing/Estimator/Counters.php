<?php

declare(strict_types=1);

namespace App\Domain\Routing\Estimator;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 T-06 / FS-03 — the per-queue ROLLING COUNTERS: assignments per 30 minutes, kept
 * as one-minute buckets on redis-volatile (`t:{tenant}:routing:est:{queue}:{minute}`, each
 * with a 31-minute TTL — C-07: prefixed, TTL'd, never the source of truth; the invalidation
 * is the TTL itself). Fed from `ticket.assigned` by the AssignmentStatsConsumer (every
 * assignment path — routed, claimed, manual — counts, no core edit), deduplicated per
 * event id so a redelivery (C-09) never double-counts.
 *
 * FS-03: a bucket holding a non-numeric value is a CORRUPTED counter — `throughput30()`
 * answers null and the mapper falls to the depth-only heuristic with `degraded: true`;
 * `heal()` (routing:rebuild) drops every bucket, after which the flag clears as honest
 * counts return.
 */
final class Counters
{
    public const WINDOW_MINUTES = 30;

    private const BUCKET_TTL_SECONDS = 31 * 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly RedisFactory $redis,
    ) {}

    public function bucketKey(string $queueId, int $minuteEpoch): string
    {
        return sprintf('t:%s:routing:est:%s:%d', $this->context->tenantId(), $queueId, $minuteEpoch);
    }

    /** Count one assignment into the current minute; `$dedupeId` (the event id) guards redelivery. */
    public function record(string $queueId, int $nowEpochSeconds, ?string $dedupeId = null): void
    {
        $redis = $this->redis->connection('volatile');
        if ($dedupeId !== null) {
            $seen = $redis->command('set', [sprintf('t:%s:routing:est:seen:%s', $this->context->tenantId(), $dedupeId), '1', ['NX', 'EX' => self::BUCKET_TTL_SECONDS]]);
            if ($seen === false || $seen === null) {
                return;
            }
        }
        $key = $this->bucketKey($queueId, intdiv($nowEpochSeconds, 60));
        $redis->command('incr', [$key]);
        $redis->command('expire', [$key, self::BUCKET_TTL_SECONDS]);
    }

    /** Assignments in the last 30 minutes, or NULL when a bucket is corrupted (FS-03). */
    public function throughput30(string $queueId, int $nowEpochSeconds): ?int
    {
        $minute = intdiv($nowEpochSeconds, 60);
        $keys = [];
        for ($i = 0; $i < self::WINDOW_MINUTES; $i++) {
            $keys[] = $this->bucketKey($queueId, $minute - $i);
        }
        /** @var list<mixed> $values */
        $values = (array) $this->redis->connection('volatile')->command('mget', [$keys]);
        $sum = 0;
        foreach ($values as $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if (! is_numeric($value) || (int) $value < 0) {
                return null;   // corrupted
            }
            $sum += (int) $value;
        }

        return $sum;
    }

    /** FS-03 heal: drop every live bucket (the window + one) of every live queue — deterministic keys, no KEYS scan. */
    public function heal(int $nowEpochSeconds, ?string $queueId = null): int
    {
        /** @var list<string> $queues */
        $queues = $queueId !== null ? [$queueId] : DB::table('queues')->whereNull('deleted_at')->pluck('id')->all();
        $minute = intdiv($nowEpochSeconds, 60);
        $redis = $this->redis->connection('volatile');
        $dropped = 0;
        foreach ($queues as $queue) {
            for ($i = -1; $i <= self::WINDOW_MINUTES + 1; $i++) {
                $deleted = $redis->command('del', [$this->bucketKey($queue, $minute - $i)]);
                $dropped += is_int($deleted) ? $deleted : 0;
            }
        }

        return $dropped;
    }
}
