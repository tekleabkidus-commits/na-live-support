<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Window;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * FR-22/T-04: the 5-minute reply-window sweep. Reads the ZSET index by expiry
 * score and emits telegram.window.closing|expired on TRANSITIONS only (the
 * stored reply_window_state gates re-emission). Every inbound/outbound already
 * updates state immediately (WindowTracker), so the sweep is the safety net,
 * not the primary path — drift ≤5 min (NFR-03).
 *
 * FS-01 (the reason calibration/outage tests come BEFORE this is trusted):
 * during a VERIFIED outage (the conversation's bot channel is health=down)
 * the sweep PAUSES expiry transitions — no false "expired" while we simply
 * cannot reach Telegram. The window stays in the ZSET and transitions when
 * health recovers (with a recompute). closing is still announced (it is
 * advisory), but expired is withheld under outage.
 *
 * The sweep reads the ZSET (score = integer µs, R-23) via ZRANGEBYSCORE and
 * is O(due), not O(all).
 */
final class WindowSweep
{
    private const CONNECTION = 'volatile';

    private const CLOSING_BAND_SECONDS = 7200; // FR-22: ≤2 h left is "closing"

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly WindowTracker $tracker,
        private readonly ChannelHealthReader $health,
        private readonly EventPublisher $events,
    ) {}

    /** @return array{closing: int, expired: int, paused: int} */
    public function run(int $nowMicros): array
    {
        $connection = $this->redis->connection(self::CONNECTION);
        $closingCeiling = $nowMicros + self::CLOSING_BAND_SECONDS * 1_000_000;

        // Everything at or before the closing ceiling is in play (closing or
        // already expired). O(due), not O(all).
        /** @var list<string> $due */
        $due = $connection->command('zrangebyscore', [$this->tracker->zsetKey(), '-inf', (string) $closingCeiling]);

        $counts = ['closing' => 0, 'expired' => 0, 'paused' => 0];

        foreach ($due as $conversationId) {
            $row = DB::table('conversations')
                ->where('id', $conversationId)
                ->first(['channel', 'channel_ref_id', 'reply_window_expires_at', 'reply_window_state']);

            if ($row === null || $row->reply_window_expires_at === null) {
                $connection->command('zrem', [$this->tracker->zsetKey(), $conversationId]);

                continue;
            }

            $expiryMicros = $this->tracker->toMicros(is_scalar($row->reply_window_expires_at) ? (string) $row->reply_window_expires_at : '');
            $isExpired = $expiryMicros <= $nowMicros;

            if ($isExpired) {
                // FS-01: under a verified outage, withhold the expired
                // transition — no false "expired".
                if ($this->health->isDown(is_scalar($row->channel) ? (string) $row->channel : '', is_scalar($row->channel_ref_id) ? (string) $row->channel_ref_id : '')) {
                    $counts['paused']++;

                    continue;
                }

                if ($row->reply_window_state !== 'expired') {
                    $this->transition($conversationId, 'expired', is_scalar($row->reply_window_expires_at) ? (string) $row->reply_window_expires_at : '');
                    $counts['expired']++;
                }
                // Expired windows leave the sweep index; a reopen re-adds them.
                $connection->command('zrem', [$this->tracker->zsetKey(), $conversationId]);

                continue;
            }

            // Not yet expired but within the closing band.
            if ($row->reply_window_state !== 'closing') {
                $this->transition($conversationId, 'closing', is_scalar($row->reply_window_expires_at) ? (string) $row->reply_window_expires_at : '');
                $counts['closing']++;
            }
        }

        return $counts;
    }

    private function transition(string $conversationId, string $state, string $expiresAt): void
    {
        DB::table('conversations')->where('id', $conversationId)->update(['reply_window_state' => $state]);

        $this->events->publish('telegram.window.'.$state, [
            'tenant_id' => $this->context->tenantId(),
            'conversation_id' => $conversationId,
            'expires_at' => $expiresAt,
        ]);
    }
}
