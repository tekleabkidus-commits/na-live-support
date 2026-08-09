<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Window;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Reads Doc 04's channel-health SIGNAL for FS-01, through its documented
 * volatile-Redis representation — `t:{tenant}:channel-health:{channel}:{ref}`,
 * hash field `status` (ChannelHealthTracker writes it). This is Doc 05
 * consuming a Doc 04 signal through its persisted form, exactly like a durable
 * consumer reads an event — so Doc 04 stays untouched (no core edit). The key
 * contract is the dependency; if ChannelHealthTracker ever changes its key
 * shape, this reader's test goes red.
 */
final class ChannelHealthReader
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function isDown(string $channel, string $channelRefId): bool
    {
        $key = sprintf('t:%s:channel-health:%s:%s', $this->context->tenantId(), $channel, $channelRefId);
        $status = $this->redis->connection('volatile')->command('hget', [$key, 'status']);

        return $status === 'down';
    }
}
