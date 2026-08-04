<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * FR-16's token bucket, per channel endpoint. Refill rate and capacity come from
 * the A-5 settings (`messaging.rate.outbound_per_second`, `…outbound_burst` —
 * NFR-02's 50 msg/s burst figure is the default's source); adapter capability
 * remains the harder cap where lower, but FR-08's capability shape carries no
 * rate field today — when a Doc 05/13 adapter grows one, it intersects HERE, not
 * in a channel conditional.
 *
 * Over-limit is a WAIT, never a drop (FR-16's own words): the caller leaves the
 * message in `queued` and the lane tries again next cycle — which is why refusal
 * is a boolean, not an exception.
 *
 * The read-modify-write below is not atomic across workers. Today one lane
 * worker serves a tenant's lanes (R-02's per-lane concurrency relaxation is
 * RESERVED, not built), so there is no second writer; if that changes, this
 * becomes a Lua script before it becomes a race.
 *
 * Volatile Redis, deliberately: an evicted bucket refills to burst — a brief
 * over-send on eviction, never a stall.
 */
final class RateGate
{
    private const CONNECTION = 'volatile';

    /** Bucket idle longer than this is garbage; TTL keeps volatile tidy (C-07). */
    private const BUCKET_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
    ) {}

    /** True consumes one token; false means wait-in-queued (FR-16). */
    public function allowSend(string $channel, string $endpoint, CarbonImmutable $now): bool
    {
        $rate = $this->intSetting('messaging.rate.outbound_per_second');
        $burst = $this->intSetting('messaging.rate.outbound_burst');

        $key = sprintf('t:%s:rate:%s:%s', $this->context->tenantId(), $channel, $endpoint);
        $connection = $this->redis->connection(self::CONNECTION);

        /** @var array<int|string, mixed>|false $stored */
        $stored = $connection->command('hgetall', [$key]);

        $nowStamp = (float) $now->format('U.u');
        $tokens = (float) $burst;
        $refreshedAt = $nowStamp;

        if (is_array($stored) && is_numeric($stored['tokens'] ?? null) && is_numeric($stored['refreshed_at'] ?? null)) {
            $elapsed = max(0.0, $nowStamp - (float) $stored['refreshed_at']);
            $tokens = min((float) $burst, (float) $stored['tokens'] + $elapsed * $rate);
        }

        if ($tokens < 1.0) {
            return false;
        }

        $connection->command('hmset', [$key, ['tokens' => (string) ($tokens - 1.0), 'refreshed_at' => (string) $refreshedAt]]);
        $connection->command('expire', [$key, self::BUCKET_TTL_SECONDS]);

        return true;
    }

    private function intSetting(string $key): int
    {
        $value = $this->settings->get($key);

        if (! is_int($value)) {
            throw new RuntimeException(sprintf('setting %s must be an int (registered validation); got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }
}
