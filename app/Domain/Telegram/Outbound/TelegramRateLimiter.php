<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Outbound;

use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * FR-20/T-08/R-05: Telegram send rate limits as Redis-Lua PHYSICS. Two token
 * buckets, BOTH must allow a send:
 *   - per-bot   default 25 msg/s (telegram.rate.bot_per_second), no burst pool
 *     beyond the per-second rate;
 *   - per-chat  1 msg/s sustained, burst 3 (telegram.rate.chat_per_second /
 *     telegram.rate.chat_burst).
 *
 * The check-and-consume is a single Lua eval per bucket, so concurrent lane
 * workers can never both consume the last token (the RateGate read-modify-
 * write race, closed at the source). 429s feed ADAPTIVE SLOWDOWN: the per-bot
 * refill drops 20% for 60 s (a penalty flag on the bucket), degrading
 * gracefully rather than hammering (R-05). Per-chat FIFO order (AC-06) is the
 * lane's — the limiter only paces; it never reorders.
 *
 * Volatile Redis: an evicted bucket refills to full, a brief over-send on
 * eviction, never a stall (the RateGate posture).
 */
final class TelegramRateLimiter
{
    private const CONNECTION = 'volatile';

    private const TTL_SECONDS = 120;

    private const PENALTY_SECONDS = 60;

    private const PENALTY_FACTOR = 0.8;

    /**
     * Atomic token bucket: refill by elapsed*rate up to capacity, consume one
     * if available. KEYS[1]=bucket, KEYS[2]=penalty. ARGV: now(µs float),
     * rate, capacity, ttl, penalty_factor.
     */
    private const LUA = <<<'LUA'
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

    /** Both buckets must allow. False → wait in queued (never dropped, FR-20). */
    public function allow(string $botId, string $chatId, float $nowMicros): bool
    {
        $botRate = $this->intSetting('telegram.rate.bot_per_second');
        $chatRate = $this->intSetting('telegram.rate.chat_per_second');
        $chatBurst = $this->intSetting('telegram.rate.chat_burst');

        // Per-bot first (the global ceiling), then per-chat. If the per-bot
        // bucket refuses we do NOT consume a per-chat token.
        if (! $this->consume($this->botKey($botId), $this->penaltyKey($botId), $botRate, $botRate, $nowMicros)) {
            return false;
        }

        return $this->consume($this->chatKey($botId, $chatId), 'noop:penalty', $chatRate, $chatBurst, $nowMicros);
    }

    /** R-05: a 429 sets the per-bot slowdown penalty (refill −20% for 60 s). */
    public function penalize(string $botId): void
    {
        $this->redis->connection(self::CONNECTION)->command('set', [
            $this->penaltyKey($botId), '1', ['NX', 'EX' => self::PENALTY_SECONDS],
        ]);
    }

    public function isPenalized(string $botId): bool
    {
        return (bool) $this->redis->connection(self::CONNECTION)->command('exists', [$this->penaltyKey($botId)]);
    }

    private function consume(string $bucketKey, string $penaltyKey, int $rate, int $capacity, float $now): bool
    {
        // phpredis: eval(script, [KEYS..., ARGV...], numKeys).
        /** @var mixed $result */
        $result = $this->redis->connection(self::CONNECTION)->command('eval', [
            self::LUA,
            [
                $bucketKey, $penaltyKey,
                (string) $now, (string) $rate, (string) $capacity, (string) self::TTL_SECONDS, (string) self::PENALTY_FACTOR,
            ],
            2,
        ]);

        return is_numeric($result) && (int) $result === 1;
    }

    private function botKey(string $botId): string
    {
        return sprintf('t:%s:tg-rate:bot:%s', $this->context->tenantId(), $botId);
    }

    private function chatKey(string $botId, string $chatId): string
    {
        return sprintf('t:%s:tg-rate:chat:%s:%s', $this->context->tenantId(), $botId, $chatId);
    }

    private function penaltyKey(string $botId): string
    {
        return sprintf('t:%s:tg-rate:penalty:%s', $this->context->tenantId(), $botId);
    }

    private function intSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_int($value)) {
            throw new RuntimeException(sprintf('setting %s must be an int; got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }
}
