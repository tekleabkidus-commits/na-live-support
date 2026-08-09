<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Direct;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * T-05/SEC-06: callback nonces. Shape `{flow_key}:{ts}:{mac}` fits Telegram's
 * 64-byte callback-data cap (flow keys are registry slugs, mac truncated to
 * 16 hex chars of an HMAC keyed on app key + bot key); validity 15 minutes;
 * SINGLE-USE via atomic SET NX on redis-volatile (C-07 prefix + TTL — the
 * volatile-nonce posture the Doc 02 webhook rulings established). A replayed
 * or expired callback answers a neutral toast and logs the event (SEC-06) —
 * the caller's job; this class only says valid/invalid.
 */
final class CallbackNonce
{
    private const VALIDITY_SECONDS = 900;

    private const MAC_LENGTH = 16;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function make(string $botKey, string $flowKey, int $timestamp): string
    {
        return sprintf('%s:%d:%s', $flowKey, $timestamp, $this->mac($botKey, $flowKey, $timestamp));
    }

    /** @return array{valid: bool, flow_key: ?string, reason: ?string} */
    public function validate(string $botKey, string $callbackData, int $now): array
    {
        $parts = explode(':', $callbackData);
        if (count($parts) !== 3 || ! ctype_digit($parts[1])) {
            return ['valid' => false, 'flow_key' => null, 'reason' => 'malformed'];
        }

        [$flowKey, $ts, $mac] = [$parts[0], (int) $parts[1], $parts[2]];

        if (! hash_equals($this->mac($botKey, $flowKey, $ts), $mac)) {
            return ['valid' => false, 'flow_key' => null, 'reason' => 'bad_mac'];
        }

        if ($now - $ts > self::VALIDITY_SECONDS || $ts > $now + 60) {
            return ['valid' => false, 'flow_key' => $flowKey, 'reason' => 'expired'];
        }

        // Single-use: first SETNX wins; a replay finds the key spent (SEC-06).
        // phpredis wants the option flags as ONE array arg, not spread
        // (['NX' => true, 'EX' => seconds]) — the atomic SET NX EX.
        $spent = $this->redis->connection('volatile')->command('set', [
            sprintf('t:%s:tg-nonce:%s', $this->context->tenantId(), hash('sha256', $callbackData)),
            '1',
            ['NX', 'EX' => self::VALIDITY_SECONDS],
        ]);

        if (! $spent) {
            return ['valid' => false, 'flow_key' => $flowKey, 'reason' => 'replayed'];
        }

        return ['valid' => true, 'flow_key' => $flowKey, 'reason' => null];
    }

    private function mac(string $botKey, string $flowKey, int $timestamp): string
    {
        $appKey = config('app.key');

        return substr(hash_hmac(
            'sha256',
            $flowKey.'|'.$timestamp.'|'.$botKey,
            is_string($appKey) ? $appKey : '',
        ), 0, self::MAC_LENGTH);
    }
}
