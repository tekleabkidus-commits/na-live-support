<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * The 48-hour inbound dedupe window (Doc 04 FR-10 step 2, T-03, EC-03).
 *
 * Channels deliver at-least-once — Telegram redelivers an update until it is ACKed,
 * and a slow ACK means the same update arrives twice. The adapter-supplied
 * dedupe_key names the delivery; SETNX on redis-volatile decides who saw it first.
 * The key is stored HASHED (channel input does not belong raw in Redis keys —
 * ReplayGuard's lesson), C-07 tenant-prefixed, with the 48 h window as its TTL.
 *
 * THE CLAIMANT VALUE EXISTS BECAUSE JOBS RETRY. The SETNX consumes the dedupe slot
 * on the FIRST attempt; if that attempt then dies (adapter error after dedupe, DB
 * blip), the queue retries the SAME job — which must pass its own dedupe mark or
 * the retry silently drops a never-persisted message (a zero-loss violation,
 * NFR-04). So the slot stores the claiming job's uuid: a retry of the same job
 * matches and proceeds; a genuine channel redelivery is a DIFFERENT job, does not
 * match, and is dropped. (A tries-exhausted job re-driven from the step-9
 * dead-letter arrives as a new job and will read as a duplicate — the re-drive
 * flow owns clearing the mark; recorded here so step 9 does not rediscover it.)
 *
 * Failure posture: an unreachable redis-volatile THROWS — the job retries with
 * backoff rather than choosing between losing the message (fail-closed drop) and
 * risking a visible duplicate row (fail-open persist). Eviction pressure on
 * volatile shortening the window is the standing Doc 18 handoff (ruling D-1:
 * eviction must spare TTL'd keys).
 */
final class InboundDedupeStore
{
    private const WINDOW_SECONDS = 172_800; // 48 h (FR-10 step 2)

    private const CONNECTION = 'volatile';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    /**
     * True if this delivery has not been seen in the window — marking it seen,
     * atomically, under $claimant. False means duplicate (EC-03): drop silently,
     * count the metric, never error back to the channel.
     */
    public function firstSight(string $channel, string $dedupeKey, string $claimant): bool
    {
        $key = sprintf(
            't:%s:inbound-dedupe:%s',
            $this->context->tenantId(),
            hash('sha256', $channel.'|'.$dedupeKey),
        );

        $connection = $this->redis->connection(self::CONNECTION);

        $set = $connection->command('set', [$key, $claimant, ['nx', 'ex' => self::WINDOW_SECONDS]]);

        if ($set !== false && $set !== null) {
            return true;
        }

        // The slot is taken. Ours (job retry) → proceed; anyone else's → duplicate.
        $holder = $connection->command('get', [$key]);

        return $holder === $claimant;
    }
}
