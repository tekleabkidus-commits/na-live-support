<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * FS-01's health signal (Doc 04; event name filed as interpretation A-4;
 * schema_version 2 and the transition-only canonical home are ruling A-22,
 * S23 plan approval): `channel.health.changed` fires on TRANSITION, never
 * per failure — a total channel outage produces one `down` event when the
 * streak crosses the threshold and one `healthy` event on the first
 * successful send after it. v2's status vocabulary is healthy|degraded|down
 * (Doc 05 FR-29); this tracker's binary view maps up->healthy, down->down —
 * `degraded` belongs to Doc 05's threshold rollup (its producer registers at
 * S23 step 11).
 *
 * Only CHANNEL-CLASS failures count (network_error, channel_unavailable):
 * blocked-by-user is a conversation fact and invalid content a payload fact —
 * neither says anything about the channel being alive. Rate limiting is the
 * channel WORKING as designed.
 *
 * The streak lives in redis-volatile (C-07 prefix + TTL; operational signal,
 * never source of truth — an evicted streak means at worst a re-announced
 * transition). Events go through the outbox in their own transaction, the
 * LaneWorker's laneSkip precedent. Doc 05/13 own the surfaces that render
 * these events (FS-01's own words); Doc 18 owns alert routing.
 */
final class ChannelHealthTracker
{
    /** Consecutive channel-class failures before a channel is called down. */
    public const DOWN_AFTER_FAILURES = 3;

    private const TTL_SECONDS = 86_400;

    private const CONNECTION = 'volatile';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function observeSuccess(string $channel, string $channelRefId): void
    {
        $connection = $this->redis->connection(self::CONNECTION);
        $key = $this->key($channel, $channelRefId);

        $state = $connection->command('hget', [$key, 'status']);

        $connection->command('hset', [$key, 'status', 'up', 'streak', 0]);
        $connection->command('expire', [$key, self::TTL_SECONDS]);

        if ($state === 'down') {
            $this->announce($channel, $channelRefId, 'healthy'); // A-22: v2 vocabulary
        }
    }

    public function observeFailure(string $channel, string $channelRefId, ?DeliveryFailureReason $reason): void
    {
        if (! in_array($reason, [DeliveryFailureReason::NetworkError, DeliveryFailureReason::ChannelUnavailable], true)) {
            return;
        }

        $connection = $this->redis->connection(self::CONNECTION);
        $key = $this->key($channel, $channelRefId);

        $incremented = $connection->command('hincrby', [$key, 'streak', 1]);
        $streak = is_numeric($incremented) ? (int) $incremented : 0;
        $connection->command('expire', [$key, self::TTL_SECONDS]);

        $state = $connection->command('hget', [$key, 'status']);

        if ($streak >= self::DOWN_AFTER_FAILURES && $state !== 'down') {
            $connection->command('hset', [$key, 'status', 'down']);
            $this->announce($channel, $channelRefId, 'down');
        }
    }

    private function announce(string $channel, string $channelRefId, string $status): void
    {
        DB::transaction(function () use ($channel, $channelRefId, $status): void {
            $this->events->publish('channel.health.changed', [
                'tenant_id' => $this->context->tenantId(),
                'channel' => $channel,
                'channel_ref_id' => $channelRefId,
                'status' => $status,
            ]);

            $this->audit->record(
                actionKey: 'channel.health_changed',
                entity: 'channel',
                entityId: null,
                actorType: AuditActor::System,
                payload: [
                    'channel' => $channel,
                    'channel_ref_id' => $channelRefId,
                    'status' => $status,
                ],
            );
        });
    }

    private function key(string $channel, string $channelRefId): string
    {
        return sprintf('t:%s:channel-health:%s:%s', $this->context->tenantId(), $channel, $channelRefId);
    }
}
