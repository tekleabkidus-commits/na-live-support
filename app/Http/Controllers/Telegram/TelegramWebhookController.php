<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Middleware\ResolveTenant;
use App\Jobs\Telegram\RouteTelegramUpdate;
use App\Models\TelegramBot;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaCheckUnavailable;
use App\Support\Quota\QuotaMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The REAL Telegram webhook edge (Doc 05 FR-02 riding Doc 04 FR-10 step 1):
 * authenticate (the middleware stack's job, already done by the time this
 * runs), quota-gate, enqueue raw, ACK 202 — NEVER process inline
 * (NFR-02's ACK budget).
 *
 * Bot resolution ORDER (T-02): the binder resolved the key BEFORE the secret
 * middleware verified the header; this controller only maps the resolved key
 * id to its bot row. A key with no bot is 404 (C-10's posture — no
 * existence probing), which cannot happen through the wizard's write path
 * and therefore indicates operator surgery.
 */
final class TelegramWebhookController
{
    public function __construct(private readonly QuotaMeter $quota) {}

    public function ingest(Request $request): JsonResponse
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        $bot = TelegramBot::query()
            ->where('key_id', is_string($keyId) ? $keyId : '')
            ->where('status', 'active')
            ->first();

        if ($bot === null) {
            throw new NotFoundHttpException('telegram.webhook.no_bot_for_key');
        }

        // Doc 01 S2 inherited row: the ingress quota rides the same meter as
        // every other inbound surface. On METERING OUTAGE this edge degrades
        // OPEN with a metric — the S15 FS ruling's own reasoning applied to
        // its sibling control: "capacity protection, not authentication —
        // fails OPEN ... capacity protection must not convert a cache outage
        // into a channel outage." The bounded-queue backpressure
        // QuotaCheckUnavailable asks for is the queue itself; a 500 here
        // would tell Telegram to hammer a degraded platform. Stated as an
        // interpretation in the S23 step-4 checkpoint.
        try {
            $this->quota->allow(Quota::MessagesPerMinute);
        } catch (QuotaCheckUnavailable $e) {
            Log::warning('telegram webhook quota degraded open: metering unreachable', [
                'exception' => $e::class,
                'metric' => 'quota.check_degraded',
            ]);
        }

        /** @var array<string, mixed> $update */
        $update = $request->json()->all();

        // FR-30: the last-50 raw-update capture (best-effort inside the class;
        // a redis blip never costs the ACK). Stored raw, read masked-only
        // (SEC-03/EC-12 — the read path applies UpdateMasker, always).
        app(\App\Domain\Telegram\Health\RawUpdateLog::class)->push($bot->id, $update);

        RouteTelegramUpdate::dispatch($bot->id, $update);

        return new JsonResponse(['accepted' => true], 202);
    }
}
