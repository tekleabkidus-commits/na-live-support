<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * FS-01's staleness signal: the projector heartbeats on every applied event;
 * the workspace reads the gap. Lists FREEZE CORRECT-AS-OF when the projector
 * is down (the ZSETs simply stop moving — nothing here fabricates
 * freshness), the pill appears past 30 s, and NO agent action is blocked —
 * writes ride the normal APIs untouched. HONEST CORRECTION (step 14): this
 * docblock used to say the pill was "rendered at step 8" — it was not; the
 * prop was plumbed and nothing consumed it, found when the relay ruling's
 * FS-01 re-drill went looking for the surface it had to prove. The banner
 * renders at step 14, fed by the 30 s status heartbeat, and shows only for
 * staleness WITH pending work (a quiet stack is not an outage).
 *
 * The heartbeat is OPS METADATA, not an ordering key or metric (R-19b
 * analysis: wall-clock seconds are fine here; nothing orders by it, and the
 * staleness test drives the STORED VALUE, never a sleep).
 *
 * SABOTAGE #18's TARGET: suppress the heartbeat (or hardcode staleSeconds)
 * and the FS-01 staleness proof in InboxResilienceTest goes red.
 */
final class ProjectorStatus
{
    private const CONNECTION = 'volatile';

    public const STALE_AFTER_SECONDS = 30;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function key(): string
    {
        return sprintf('t:%s:inbox:projector_heartbeat', $this->context->tenantId());
    }

    /** Called by the consumer after every applied event. */
    public function beat(): void
    {
        $this->redis->connection(self::CONNECTION)->command('set', [$this->key(), (string) time()]);
    }

    /** Seconds since the last applied event; null = never beat (cold tenant). */
    public function staleSeconds(): ?int
    {
        /** @var string|false|null $raw */
        $raw = $this->redis->connection(self::CONNECTION)->command('get', [$this->key()]);
        if ($raw === false || $raw === null) {
            return null;
        }

        return max(0, time() - (int) $raw);
    }

    public function isStale(): bool
    {
        $stale = $this->staleSeconds();

        return $stale !== null && $stale > self::STALE_AFTER_SECONDS;
    }
}
