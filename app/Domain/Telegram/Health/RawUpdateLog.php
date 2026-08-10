<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Health;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * FR-30's last-50 raw updates, per bot: a capped redis-volatile list written
 * at the webhook edge (best-effort — a redis blip must never cost the 202
 * ACK) and read MASKED-ONLY through UpdateMasker (SEC-03). C-07: mandatory
 * TTL, cache-not-truth (losing the list loses a diagnostic view, nothing
 * else), tenant-prefixed key.
 *
 * The stored entry carries the RAW payload (EC-12: "stored but masked in
 * viewers") — there is no unmasked read path on this class: read() masks.
 */
final class RawUpdateLog
{
    private const KEEP = 50;

    private const TTL_SECONDS = 259_200; // 72 h

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly UpdateMasker $masker,
    ) {}

    /** @param array<string, mixed> $update */
    public function push(string $botId, array $update): void
    {
        try {
            $connection = $this->redis->connection('volatile');
            $key = $this->key($botId);
            // received_at is DISPLAY metadata for the diagnostic viewer only
            // (R-19 posture: the list's own order is the ordering; nothing
            // computes from this value, so edge wall-clock is acceptable
            // where a DB round-trip on the ACK path would not be).
            $connection->command('lpush', [$key, json_encode([
                'received_at' => gmdate('c'),
                'update' => $update,
            ], JSON_THROW_ON_ERROR)]);
            $connection->command('ltrim', [$key, 0, self::KEEP - 1]);
            $connection->command('expire', [$key, self::TTL_SECONDS]);
        } catch (\Throwable) {
            // Best-effort: the viewer is a diagnostic, the ACK is the contract.
        }
    }

    /**
     * The last ≤50 updates, NEWEST FIRST, every entry masked (SEC-03).
     *
     * @return list<array{received_at: string, update: array<array-key, mixed>}>
     */
    public function read(string $botId): array
    {
        $raw = $this->redis->connection('volatile')->command('lrange', [$this->key($botId), 0, self::KEEP - 1]);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $json) {
            if (! is_string($json)) {
                continue;
            }
            $entry = json_decode($json, true);
            if (! is_array($entry) || ! is_array($entry['update'] ?? null)) {
                continue;
            }
            $out[] = [
                'received_at' => is_string($entry['received_at'] ?? null) ? $entry['received_at'] : '',
                'update' => $this->masker->mask($entry['update']),
            ];
        }

        return $out;
    }

    private function key(string $botId): string
    {
        return sprintf('t:%s:tg-raw:%s', $this->context->tenantId(), $botId);
    }
}
