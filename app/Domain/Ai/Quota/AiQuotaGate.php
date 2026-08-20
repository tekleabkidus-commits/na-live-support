<?php

declare(strict_types=1);

namespace App\Domain\Ai\Quota;

use App\Support\Quota\Quota;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaMeter;
use App\Support\Quota\QuotaSurface;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NFR-06/EC-12/T-07 over Doc 01's quota engine — CONSUMED, never forked:
 *
 *   PRE-CALL (NFR-06, SEC-05's posture): admit() reads headroom() (the ruled
 *   core edit) — used >= limit − reserve ⇒ EXHAUSTED: the orchestrator
 *   refuses BEFORE any provider call and the caller routes straight to a
 *   human (quota_exhausted). The 5% reserve band (ai.quota.reserve_permille)
 *   is what lets the CURRENT turn finish: a turn admitted just under the
 *   band can spend into it without allow()'s hard 100% refusal cutting it
 *   mid-answer (EC-12's "completes the current turn from reserve headroom").
 *   A METERING OUTAGE lands in RESERVE MODE (refuse new turns) — never
 *   open-throttle, never bill blind (the countersign's required fixture).
 *
 *   POST-CALL (T-07): record() accumulates the provider's ACTUAL token count
 *   through allow(actual) — the SAME counter quota:reconcile persists daily
 *   (FS-05, consumed) and the same 80% alert fires from (NFR-06, built). A
 *   QuotaExceeded here is swallowed WITH a metric: the work is done, and the
 *   next pre-call admit() refuses.
 *
 * Sabotage #12 moves the admit() call post-call; the EC-12 fixture goes red.
 */
final class AiQuotaGate
{
    public function __construct(
        private readonly QuotaMeter $meter,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return 'allowed'|'exhausted' */
    public function admit(): string
    {
        try {
            $headroom = $this->meter->headroom(Quota::AiTokensPerDay);
        } catch (Throwable $e) {
            Log::warning('ai quota headroom unavailable — reserve mode (refusing new turns)', [
                'exception' => $e::class,
                'metric' => 'ai.quota.headroom_unavailable',
            ]);

            return 'exhausted';
        }

        $reserve = (int) ceil($headroom['limit'] * $this->reservePermille() / 1000);

        return $headroom['used'] >= max(0, $headroom['limit'] - $reserve) ? 'exhausted' : 'allowed';
    }

    public function record(int $actualTokens): void
    {
        if ($actualTokens <= 0) {
            return;
        }

        try {
            $this->meter->allow(Quota::AiTokensPerDay, $actualTokens, QuotaSurface::Staff);
        } catch (QuotaExceeded) {
            // The meter COMPENSATES a refused allow() (its SEC-05 contract:
            // never count past the limit) — so the reserve spend would leave
            // the counter BELOW the band and re-admit the next turn. Fill the
            // counter TO the limit instead: the gate's state is "exhausted"
            // from here (the next admit() refuses — EC-12's soft landing);
            // the exact overflow is logged with a metric. Accounting never
            // depended on this counter — AC-10 reconciles from
            // ai_decisions' per-call tokens.
            try {
                $headroom = $this->meter->headroom(Quota::AiTokensPerDay);
                $fill = max(0, $headroom['limit'] - $headroom['used']);
                if ($fill > 0) {
                    $this->meter->allow(Quota::AiTokensPerDay, $fill, QuotaSurface::Staff);
                }
                Log::info('ai tokens spent past the daily cap (reserve spend)', [
                    'tokens' => $actualTokens,
                    'overflow' => $actualTokens - $fill,
                    'metric' => 'ai.quota.reserve_spend',
                ]);
            } catch (Throwable $e) {
                Log::warning('ai reserve-spend fill failed — the counter may under-read until reconcile', [
                    'exception' => $e::class,
                    'metric' => 'ai.quota.reserve_fill_failed',
                ]);
            }
        }
    }

    private function reservePermille(): int
    {
        $raw = $this->settings->get('ai.quota.reserve_permille');

        return is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 50);
    }
}
