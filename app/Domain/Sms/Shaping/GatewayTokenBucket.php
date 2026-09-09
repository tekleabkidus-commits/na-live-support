<?php

declare(strict_types=1);

namespace App\Domain\Sms\Shaping;

use App\Domain\Sms\Catalog\Lane;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Doc 14 T-04 / NFR-02 / BR-05 / FS-05 — per-gateway Redis token buckets with
 * the three-lane priority.
 *
 * THE LUA IS A MIRROR of Doc 05's TelegramRateLimiter (T-08's pattern; the
 * consume-or-mirror ruling STOP-K: that class's public doors and settings are
 * Telegram-bound, its private `consume()` is the reusable asset) — byte-
 * identical, pinned by TokenBucketTest's drift test against the source class
 * so the mirror cannot diverge silently. Source: App\Domain\Telegram\Outbound\
 * TelegramRateLimiter::LUA (S23 step 7).
 *
 * Two buckets per gateway, one Lua eval each: the GATEWAY bucket (rate =
 * capacity = `limits.throughput_per_second`) every lane must pass, and a
 * BROADCAST sub-bucket (rate = capacity × (1 − `sms.lane.reserve_fraction`))
 * the broadcast lane must ALSO pass — the reserve is what keeps a resolved-
 * notification from waiting behind an announcement (BR-05) on the SAME
 * gateway. Over-limit is a WAIT, never a drop (the caller re-queues with a
 * delay). FS-05: a bucket key that does not exist (a Redis loss) is seeded at
 * HALF capacity before the first eval — under-send and ramp, never over-send
 * into carrier penalties.
 */
final class GatewayTokenBucket
{
    private const CONNECTION = 'volatile';

    private const TTL_SECONDS = 120;

    /** MIRROR of TelegramRateLimiter::LUA — byte-identical (the drift test pins it). */
    public const LUA = <<<'LUA'
        local now = tonumber(ARGV[1])
        local rate = tonumber(ARGV[2])
        local capacity = tonumber(ARGV[3])
        local ttl = tonumber(ARGV[4])
        local penalty_factor = tonumber(ARGV[5])
        if redis.call('EXISTS', KEYS[2]) == 1 then
            rate = rate * penalty_factor
        end
        local data = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
        local tokens = tonumber(data[1])
        local ts = tonumber(data[2])
        if tokens == nil then
            tokens = capacity
            ts = now
        end
        local elapsed = now - ts
        if elapsed < 0 then elapsed = 0 end
        tokens = math.min(capacity, tokens + elapsed * rate)
        local allowed = 0
        if tokens >= 1 then
            tokens = tokens - 1
            allowed = 1
        end
        redis.call('HMSET', KEYS[1], 'tokens', tokens, 'ts', now)
        redis.call('EXPIRE', KEYS[1], ttl)
        return allowed
    LUA;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * True when the gateway bucket (and, for the broadcast lane, the reserve sub-bucket) allows one send NOW.
     * The broadcast lane passes its OWN sub-bucket FIRST: a refused broadcast never consumes a gateway token,
     * so an announcement burst cannot drain the tokens the transactional lane needs (BR-05).
     */
    public function allow(string $gatewayId, int $ratePerSecond, Lane $lane, float $nowMicros): bool
    {
        $capacity = max(1, $ratePerSecond);
        if ($lane === Lane::Broadcast) {
            $broadcastRate = $this->broadcastRate($capacity);
            $broadcastKey = $this->key($gatewayId, 'broadcast');
            $this->seedConservativelyIfLost($broadcastKey, $broadcastRate, $nowMicros);
            if (! $this->consume($broadcastKey, $broadcastRate, $broadcastRate, $nowMicros)) {
                return false;
            }
        }
        $gatewayKey = $this->key($gatewayId, 'gw');
        $this->seedConservativelyIfLost($gatewayKey, $capacity, $nowMicros);

        return $this->consume($gatewayKey, $capacity, $capacity, $nowMicros);
    }

    /** §12: broadcast throughput = min(per-broadcast cap, gateway shape, lane weight) — the lane's share of the bucket. */
    public function broadcastRate(int $ratePerSecond): int
    {
        return max(1, (int) floor(max(1, $ratePerSecond) * (1 - $this->reserveFraction())));
    }

    private function consume(string $bucketKey, int $rate, int $capacity, float $now): bool
    {
        /** @var mixed $result */
        $result = $this->redis->connection(self::CONNECTION)->command('eval', [
            self::LUA,
            [$bucketKey, 'noop:penalty', (string) $now, (string) $rate, (string) $capacity, (string) self::TTL_SECONDS, '1'],
            2,
        ]);

        return is_numeric($result) && (int) $result === 1;
    }

    /** FS-05: a lost bucket starts THROTTLED (half capacity) and ramps at the rate — never a full burst after a Redis loss. */
    private function seedConservativelyIfLost(string $key, int $capacity, float $now): void
    {
        $exists = (bool) $this->redis->connection(self::CONNECTION)->command('exists', [$key]);
        if (! $exists) {
            $this->redis->connection(self::CONNECTION)->command('hmset', [$key, ['tokens' => (string) max(1, intdiv($capacity, 2)), 'ts' => (string) $now]]);
            $this->redis->connection(self::CONNECTION)->command('expire', [$key, self::TTL_SECONDS]);
        }
    }

    private function reserveFraction(): float
    {
        $raw = $this->settings->get('sms.lane.reserve_fraction');
        $fraction = is_numeric($raw) ? (float) $raw : 0.2;

        return min(0.9, max(0.0, $fraction));
    }

    private function key(string $gatewayId, string $bucket): string
    {
        return sprintf('t:%s:sms-rate:%s:%s', $this->context->tenantId(), $bucket, $gatewayId);
    }
}
