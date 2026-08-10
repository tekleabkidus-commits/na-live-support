<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Health;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * The FR-03 header indicator's read state: latest health status per
 * (channel, channel_ref_id), per tenant — written ONLY by the
 * HealthIndicatorConsumer from `channel.health.changed` v2 payloads.
 *
 * TRANSITION-ONLY SEMANTICS ARE CONSUMED, NEVER RE-DERIVED (A-22 / inherited
 * row 5): the event fires on transition; this store keeps the latest state
 * word per endpoint and nothing else — no heartbeats, no polling, no
 * inference. Idempotent by construction: storing the same transition twice
 * is the same state (C-09).
 *
 * redis-volatile with C-07's TTL; loss self-heals on the next transition and
 * the indicator renders "unknown" (never a fabricated healthy) meanwhile.
 */
final class ChannelHealthState
{
    private const CONNECTION = 'volatile';

    private const TTL_SECONDS = 604_800; // 7 days

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function key(): string
    {
        return sprintf('t:%s:inbox:channel_health', $this->context->tenantId());
    }

    public function set(string $channel, string $channelRefId, string $status): void
    {
        $connection = $this->redis->connection(self::CONNECTION);
        $connection->command('hset', [$this->key(), $channel.':'.$channelRefId, $status]);
        $connection->command('expire', [$this->key(), self::TTL_SECONDS]);
    }

    /** @return array<string, string> "{channel}:{ref}" => healthy|degraded|down */
    public function all(): array
    {
        /** @var array<string, string> $states */
        $states = $this->redis->connection(self::CONNECTION)->command('hgetall', [$this->key()]);

        return $states;
    }
}
