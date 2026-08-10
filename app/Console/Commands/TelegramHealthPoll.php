<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telegram\Api\TelegramApi;
use App\Domain\Telegram\Health\HealthRollup;
use App\Domain\Telegram\Health\SilentWebhookVerdict;
use App\Models\TelegramBot;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FR-29's POLLED half + FS-02 detection (Doc 05, S23 step 11): every 5
 * minutes, per active bot — getWebhookInfo through the pinned proxy client,
 * snapshot the webhook state onto the row, evaluate FS-02, apply the rollup
 * (transition-only side effects live in HealthRollup).
 *
 * A Telegram API failure during the poll is itself a signal: the snapshot
 * records the transport error and the rollup input treats the bot as
 * unpollable (degraded floor), never throws the pass away.
 *
 * FS-02's alert is the ModePolicy precedent: a structured log signal with
 * its metric, riding the registered category notify.telegram.webhook_silent
 * (C3) — delivery machinery is Doc 07/Doc 18's.
 */
final class TelegramHealthPoll extends Command
{
    use RunsWithinTenant;

    protected $signature = 'telegram:health-poll';

    protected $description = 'Poll getWebhookInfo per active bot; FS-02 detection; FR-29 rollup (Doc 05)';

    public function handle(TelegramApi $api): int
    {
        /** @var list<object{tenant_id: string}> $tenants */
        $tenants = DB::select('SELECT DISTINCT tenant_id FROM telegram_bots WHERE status = \'active\'');

        $polled = 0;
        foreach ($tenants as $row) {
            $polled += (int) $this->withinTenant($row->tenant_id, fn (): int => $this->pollTenant($api));
        }

        $this->info(sprintf('polled %d bot(s)', $polled));

        return self::SUCCESS;
    }

    private function pollTenant(TelegramApi $api): int
    {
        $count = 0;
        foreach (TelegramBot::query()->where('status', 'active')->cursor() as $bot) {
            $this->pollBot($api, $bot);
            $count++;
        }

        return $count;
    }

    private function pollBot(TelegramApi $api, TelegramBot $bot): void
    {
        /** @var HealthRollup $rollup */
        $rollup = app(HealthRollup::class);

        $pendingAttr = $bot->getAttribute('webhook_pending_updates');
        $previousPending = is_numeric($pendingAttr) ? (int) $pendingAttr : 0;
        $token = $bot->bot_token;

        $info = null;
        if (is_string($token)) {
            try {
                $info = $api->call($token, 'getWebhookInfo');
            } catch (Throwable $e) {
                // Unpollable is a snapshot fact, not a crash: record and let
                // the rollup treat it as at-least-degraded via FS-02 inputs.
                DB::update(
                    'UPDATE telegram_bots SET webhook_last_error = ?, last_polled_at = clock_timestamp() WHERE id = ?',
                    ['poll: '.$e::class, $bot->id],
                );
            }
        }

        $pending = is_array($info) && is_numeric($info['pending_update_count'] ?? null)
            ? (int) $info['pending_update_count'] : $previousPending;
        $lastError = is_array($info) && is_string($info['last_error_message'] ?? null)
            ? $info['last_error_message'] : null;
        // Telegram's last_error_date is CHANNEL METADATA (EC-13/R-19):
        // displayed, never ordering.
        $lastErrorAt = is_array($info) && is_numeric($info['last_error_date'] ?? null)
            ? gmdate('c', (int) $info['last_error_date']) : null;

        if (is_array($info)) {
            DB::update(
                'UPDATE telegram_bots SET webhook_pending_updates = ?, webhook_last_error = ?, webhook_last_error_at = ?, last_polled_at = clock_timestamp() WHERE id = ?',
                [$pending, $lastError, $lastErrorAt, $bot->id],
            );
        }

        $verdict = SilentWebhookVerdict::evaluate(
            previousPending: $previousPending,
            currentPending: $pending,
            normallyActive: $this->normallyActive($bot->id),
            silencePastThreshold: $this->silencePastThreshold($bot->id),
        );

        if ($verdict->broken) {
            Log::warning('telegram webhook silently broken: pending updates growing with no inbound', [
                'metric' => 'telegram.webhook.silent_broken',
                'bot_id' => $bot->id,
                'pending_update_count' => $verdict->pendingCount,
                // Rides notify.telegram.webhook_silent (registered, C3);
                // the one-click repair is re-register (FS-02).
            ]);
        }

        $rollup->apply($bot, $rollup->evaluate($bot, $verdict));
    }

    /** Any inbound within 7 days = normally active (FS-02's "normally-active bot"). */
    private function normallyActive(string $botId): bool
    {
        $row = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM telegram_bots WHERE id = ? AND last_inbound_at > clock_timestamp() - interval '7 days') AS e",
            [$botId],
        );

        return is_object($row) && (bool) ($row->e ?? false);
    }

    private function silencePastThreshold(string $botId): bool
    {
        /** @var SettingsRepository $settings */
        $settings = app(SettingsRepository::class);
        $minutes = $settings->get('telegram.health.silent_inbound_minutes');
        $minutes = is_numeric($minutes) ? (int) $minutes : 60;

        $row = DB::selectOne(
            'SELECT (last_inbound_at IS NULL OR last_inbound_at < clock_timestamp() - make_interval(mins => ?)) AS s
               FROM telegram_bots WHERE id = ?',
            [$minutes, $botId],
        );

        return is_object($row) && (bool) ($row->s ?? true);
    }
}
