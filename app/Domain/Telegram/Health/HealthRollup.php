<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Health;

use App\Models\TelegramBot;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * FR-29: the per-bot health rollup — healthy | degraded | down — from
 * registered settings thresholds, TRANSITION-ONLY on emission (A-22).
 *
 * Two halves feed it, per FR-29's "polled 5 min + event-driven":
 * - the POLLED half is TelegramHealthPoll (getWebhookInfo snapshot + FS-02
 *   silent-broken detection) calling apply();
 * - the EVENT-DRIVEN half is Doc 04's ChannelHealthTracker, untouched: send
 *   streaks flip the same redis health key the sweep reads, immediately.
 *
 * This class is the SOLE writer of telegram_bots.health_status. On a
 * transition it (in one transaction): updates the row with
 * clock_timestamp() provenance, publishes channel.health.changed v2 through
 * the outbox (closed payload), audits, and converges the FS-01 redis key
 * (down ⇒ 'down', else 'up' — the tracker's own binary vocabulary, so the
 * two writers cannot disagree about what the sweep pauses on).
 *
 * Rollup rules (thresholds are the registered telegram.health.* settings,
 * shown on B6-S06 F04 as "the values currently deciding the badge"):
 * - DOWN:     failure rate ≥ down_failure_pct, or FS-02 says silent-broken.
 * - DEGRADED: failure rate ≥ degraded_failure_pct, or pending updates grew
 *             across polls while inbound continued.
 * - HEALTHY:  otherwise.
 */
final class HealthRollup
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly RedisFactory $redis,
    ) {}

    /**
     * Evaluate a bot's rollup from its current row + panel metrics.
     * Pure decision — no writes.
     */
    public function evaluate(TelegramBot $bot, ?SilentWebhookVerdict $silence = null): string
    {
        $degradedPct = $this->intSetting('telegram.health.degraded_failure_pct', 10);
        $downPct = $this->intSetting('telegram.health.down_failure_pct', 50);

        $rate = $this->failureRatePct($bot->id);

        if ($silence?->broken === true || $rate >= $downPct) {
            return 'down';
        }
        if ($rate >= $degradedPct || $silence?->suspicious === true) {
            return 'degraded';
        }

        return 'healthy';
    }

    /** Apply a computed status: transition-only side effects (A-22). */
    public function apply(TelegramBot $bot, string $status): void
    {
        $previous = $this->currentStatus($bot->id);
        if ($previous === $status) {
            return; // A-22: no heartbeats, ever.
        }

        DB::transaction(function () use ($bot, $status, $previous): void {
            DB::update(
                'UPDATE telegram_bots SET health_status = ?, health_changed_at = clock_timestamp() WHERE id = ?',
                [$status, $bot->id],
            );

            $this->events->publish('channel.health.changed', [
                'tenant_id' => $this->context->tenantId(),
                'channel' => 'telegram',
                'channel_ref_id' => $bot->id,
                'status' => $status,
            ]);

            $this->audit->record(
                actionKey: 'telegram.health_rollup_changed',
                entity: 'telegram_bot',
                entityId: $bot->id,
                actorType: AuditActor::System,
                payload: ['old' => $previous, 'new' => $status],
            );
        });

        // FS-01 convergence: the sweep's pause key, in the tracker's own
        // binary vocabulary ('down'/'up') — degraded does NOT pause expiry.
        $this->redis->connection('volatile')->command('hset', [
            sprintf('t:%s:channel-health:telegram:%s', $this->context->tenantId(), $bot->id),
            'status', $status === 'down' ? 'down' : 'up',
        ]);
    }

    /**
     * FR-29's panel numbers, one query pass per block. ≤1 s rollup budget
     * (NFR: measured dev-indicative in HealthRollupTest).
     *
     * @return array{in_per_hour: int, out_per_hour: int, failure_rate_pct: float, failures_by_kind: array<string, int>, retry_queue_depth: int}
     */
    public function panelMetrics(string $botId): array
    {
        $in = $this->hourCount($botId, 'in');
        $out = $this->hourCount($botId, 'out');

        /** @var list<object{reason: string|null, n: int}> $failed */
        $failed = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.channel', 'telegram')
            ->where('conversations.channel_ref_id', $botId)
            ->where('messages.direction', 'out')
            ->whereIn('messages.delivery_state', ['failed_retryable', 'failed_final'])
            ->whereRaw("messages.created_at > clock_timestamp() - interval '24 hours'")
            ->groupBy('reason')
            ->selectRaw("messages.delivery_error->>'reason' AS reason, count(*) AS n")
            ->get()
            ->all();

        $byKind = [];
        $failedTotal = 0;
        foreach ($failed as $row) {
            $kind = is_string($row->reason) && $row->reason !== '' ? $row->reason : 'unknown';
            $byKind[$kind] = ($byKind[$kind] ?? 0) + (int) $row->n;
            $failedTotal += (int) $row->n;
        }
        arsort($byKind);

        $attempted = (int) DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.channel', 'telegram')
            ->where('conversations.channel_ref_id', $botId)
            ->where('messages.direction', 'out')
            ->whereRaw("messages.created_at > clock_timestamp() - interval '24 hours'")
            ->count();

        $depth = (int) DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.channel', 'telegram')
            ->where('conversations.channel_ref_id', $botId)
            ->where('messages.direction', 'out')
            ->whereIn('messages.delivery_state', ['queued', 'failed_retryable'])
            ->count();

        return [
            'in_per_hour' => $in,
            'out_per_hour' => $out,
            'failure_rate_pct' => $attempted > 0 ? round($failedTotal * 100 / $attempted, 1) : 0.0,
            'failures_by_kind' => $byKind,
            'retry_queue_depth' => $depth,
        ];
    }

    /** Failure percentage over the last hour of attempted sends (rollup input). */
    public function failureRatePct(string $botId): float
    {
        /** @var object{attempted: int, failed: int}|null $row */
        $row = DB::selectOne(
            "SELECT count(*) AS attempted,
                    count(*) FILTER (WHERE m.delivery_state IN ('failed_retryable', 'failed_final')) AS failed
               FROM messages m
               JOIN conversations c ON c.id = m.conversation_id
              WHERE c.channel = 'telegram'
                AND c.channel_ref_id = ?
                AND m.direction = 'out'
                AND m.created_at > clock_timestamp() - interval '1 hour'",
            [$botId],
        );

        $attempted = is_object($row) && is_numeric($row->attempted ?? null) ? (int) $row->attempted : 0;
        if ($attempted === 0) {
            return 0.0;
        }

        return round($row->failed * 100 / $attempted, 1);
    }

    private function currentStatus(string $botId): string
    {
        $status = DB::table('telegram_bots')->where('id', $botId)->value('health_status');

        return is_string($status) && $status !== '' ? $status : 'healthy';
    }

    private function hourCount(string $botId, string $direction): int
    {
        return (int) DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.channel', 'telegram')
            ->where('conversations.channel_ref_id', $botId)
            ->where('messages.direction', $direction)
            ->whereRaw("messages.created_at > clock_timestamp() - interval '1 hour'")
            ->count();
    }

    private function intSetting(string $key, int $default): int
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
