<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Domain\Telegram\Api\TelegramApi;
use App\Domain\Telegram\Health\RawUpdateLog;
use App\Domain\Telegram\Window\WindowSweep;
use App\Models\TelegramBot;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FR-30's test tools + the SEC-03-masked viewer (Doc 05 §8; B6-S06 F05/F06).
 * Permission: every route here is telegram.health.view (the committed plan's
 * mapping: "health panel/viewer/test tools"). Each tool call is audited with
 * actor (protocol #2).
 *
 * - rawUpdates: last 50, MASKED-ONLY (RawUpdateLog::read applies
 *   UpdateMasker; there is no unmasked parameter). Access itself is audited
 *   (the HistoryReader precedent — this viewer shows customer payloads).
 * - sendTest: one localized test message to an admin-supplied sandbox chat
 *   (F05/F12), via the pinned proxy client; never through conversation lanes
 *   (a diagnostic, not a conversation — N-15's spirit).
 * - simulateExpiry: STAGING ONLY — refuses in production with a stable
 *   problem code, no override parameter. On staging it moves the column
 *   (clock_timestamp() provenance) and runs the REAL sweep, so the expiry
 *   travels the same door production expiries do.
 */
final class HealthAdminController
{
    public function rawUpdates(string $botId, RawUpdateLog $log, AuditLogger $audit): JsonResponse
    {
        $bot = TelegramBot::query()->findOrFail($botId);

        $audit->record(
            actionKey: 'telegram.raw_updates_viewed',
            entity: 'telegram_bot',
            entityId: $bot->id,
            actorType: AuditActor::Staff,
            payload: ['route' => 'telegram.health.raw-updates'],
            actorId: is_string(auth()->id()) ? auth()->id() : null,
        );

        return new JsonResponse(['updates' => $log->read($bot->id)]);
    }

    public function sendTest(Request $request, string $botId, TelegramApi $api, AuditLogger $audit): JsonResponse
    {
        $bot = TelegramBot::query()->findOrFail($botId);

        /** @var array{chat_id: string} $input */
        $input = $request->validate([
            'chat_id' => ['required', 'string', 'regex:/^-?\d{1,20}$/'],
        ]);

        $token = $bot->bot_token;
        if (! is_string($token)) {
            return new JsonResponse(['code' => 'telegram.credentials_unavailable', 'detail' => __('telegram.health.no_token')], 422);
        }

        try {
            $api->call($token, 'sendMessage', [
                'chat_id' => $input['chat_id'],
                'text' => __('telegram.health.test_message'),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse(['code' => 'telegram.test_send_failed', 'detail' => $e::class], 422);
        }

        $audit->record(
            actionKey: 'telegram.test_message_sent',
            entity: 'telegram_bot',
            entityId: $bot->id,
            actorType: AuditActor::Staff,
            payload: ['chat_id' => $input['chat_id']],
            actorId: is_string(auth()->id()) ? auth()->id() : null,
        );

        return new JsonResponse(['sent' => true]);
    }

    public function simulateExpiry(Request $request, AuditLogger $audit): JsonResponse
    {
        if (app()->environment('production')) {
            // FR-30: "simulate-window-expiry (staging only)". A stable refusal
            // code, no bypass parameter — proven both ways in
            // TelegramSecurityTest.
            return new JsonResponse([
                'code' => 'telegram.simulate_expiry_production_refused',
                'detail' => __('telegram.health.simulate_refused'),
            ], 403);
        }

        /** @var array{conversation_id: string} $input */
        $input = $request->validate([
            'conversation_id' => ['required', 'uuid'],
        ]);

        $updated = DB::update(
            "UPDATE conversations SET reply_window_expires_at = clock_timestamp() - interval '1 second'
              WHERE id = ? AND channel = 'telegram' AND reply_window_expires_at IS NOT NULL",
            [$input['conversation_id']],
        );
        if ($updated === 0) {
            return new JsonResponse(['code' => 'telegram.simulate_expiry_no_window', 'detail' => __('telegram.health.simulate_no_window')], 422);
        }

        // Re-index at the new score, then run the REAL sweep door.
        $tracker = app(\App\Domain\Telegram\Window\WindowTracker::class);
        $expiry = DB::table('conversations')->where('id', $input['conversation_id'])->value('reply_window_expires_at');
        $tracker->index($input['conversation_id'], is_scalar($expiry) ? (string) $expiry : '');

        $nowRow = DB::selectOne('SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint AS m');
        $now = is_object($nowRow) && is_numeric($nowRow->m ?? null) ? (int) $nowRow->m : 0;
        $counts = app(WindowSweep::class)->run($now);

        $audit->record(
            actionKey: 'telegram.window_expiry_simulated',
            entity: 'conversation',
            entityId: $input['conversation_id'],
            actorType: AuditActor::Staff,
            payload: ['environment' => (string) app()->environment()],
            actorId: is_string(auth()->id()) ? auth()->id() : null,
        );

        return new JsonResponse(['simulated' => true, 'sweep' => $counts]);
    }
}
