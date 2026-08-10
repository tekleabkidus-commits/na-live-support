<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * FS-01's staleness signal: the projector heartbeats on every applied event;
 * the workspace reads the gap. Lists FREEZE CORRECT-AS-OF when the projector
 * is down (the ZSETs simply stop moving — nothing here fabricates
 * freshness), the pill appears past 30 s (rendered at step 8; the SIGNAL and
 * its threshold live here), and NO agent action is blocked — writes ride the
 * normal APIs untouched.
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
