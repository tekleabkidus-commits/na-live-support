<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Window;

use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FR-21/T-04: the reply-window store. `conversations.reply_window_expires_at`
 * is TRUTH; the Redis ZSET `windows:{tenant}` is the O(log n) sweep index
 * (score = expiry as INTEGER MICROSECONDS, the R-23 family principle — a
 * clock finer than the event rate, never fractional-second ties).
 *
 * THE WINDOW COMPUTES FROM THE INBOUND MESSAGE'S DB-TIME RECEIPT
 * (`messages.created_at`, clock_timestamp()), NEVER telegram_date (EC-13/
 * ADR 0010). The caller passes the message row; this class reads its DB-time
 * created_at and adds the configured window. Event-driven: every inbound
 * updates the window immediately, bypassing the 5-min sweep cadence (FR-22).
 */
final class WindowTracker
{
    private const CONNECTION = 'volatile';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * FR-24 reopen / FR-21: (re)open the window from an inbound message's
     * DB-time receipt. Returns the computed expiry (ISO, for assertions).
     */
    public function openFromInbound(string $conversationId, string $messageId): string
    {
        $windowHours = $this->intSetting('telegram.business.window_hours');

        // The expiry is DB-derived: the message's own created_at + interval,
        // computed and written in ONE statement so the app never supplies a
        // wall-clock value. telegram_date is never read here.
        $row = DB::selectOne(
            'UPDATE conversations c
                SET reply_window_expires_at = m.created_at + (? * interval \'1 hour\'),
                    reply_window_state = \'open\'
               FROM messages m
              WHERE m.id = ? AND c.id = ?
          RETURNING c.reply_window_expires_at AS expiry',
            [$windowHours, $messageId, $conversationId],
        );

        $expiry = is_object($row) ? ($row->expiry ?? null) : null;
        if (! is_string($expiry)) {
            throw new RuntimeException('window open failed: message or conversation not found in this tenant.');
        }

        $this->index($conversationId, $expiry);

        return $expiry;
    }

    /** Put/refresh the conversation in the sweep ZSET at its expiry (integer µs). */
    public function index(string $conversationId, string $expiryIso): void
    {
        $micros = $this->toMicros($expiryIso);
        $this->redis->connection(self::CONNECTION)->command('zadd', [$this->zsetKey(), $micros, $conversationId]);
    }

    public function remove(string $conversationId): void
    {
        $this->redis->connection(self::CONNECTION)->command('zrem', [$this->zsetKey(), $conversationId]);
    }

    public function zsetKey(): string
    {
        return sprintf('windows:%s', $this->context->tenantId());
    }

    /** Integer microseconds since epoch (R-23: never a fractional-second score). */
    public function toMicros(string $iso): int
    {
        $row = DB::selectOne(
            'SELECT (extract(epoch from ?::timestamptz) * 1000000)::bigint AS micros',
            [$iso],
        );

        return is_object($row) && is_numeric($row->micros ?? null) ? (int) $row->micros : 0;
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
