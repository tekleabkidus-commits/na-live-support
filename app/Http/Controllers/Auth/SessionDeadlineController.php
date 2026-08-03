<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\Sessions\SessionLifetimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The session-deadline heartbeat behind §8's timeout warning (Doc 02 §8,
 * FR-11; owner ruling, step 13).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY AN ENDPOINT AT ALL (the ruled interpretation): §8's "warning toast at
 * T-2 min" needs a deadline, and the client guessing one from the tenant's
 * idle setting drifts the moment another tab is active. This endpoint returns
 * the server's OWN two clocks, so the toast argues from the same facts the
 * expiry decision will use.
 *
 * WHAT A CALL DOES AND DOES NOT DO — the load-bearing distinction:
 *
 *   IT ROLLS THE IDLE CLOCK, exactly as every authenticated staff request
 *   does, because EnforceSessionLifetime sits ahead of it in the stack and
 *   touches LAST_ACTIVITY_AT. That is what makes "stay signed in" real: the
 *   button calls this, and the idle deadline moves.
 *
 *   IT NEVER TOUCHES THE ABSOLUTE CLOCK. ESTABLISHED_AT is stamped once at
 *   session establishment and nothing here (or anywhere) refreshes it —
 *   FR-11's absolute limit bounds a stolen session precisely because activity
 *   cannot extend it. A test asserts both halves; if this endpoint ever
 *   starts moving the absolute deadline, that is the defect the test exists
 *   to catch.
 *
 * THE RELATIVE FIELDS ARE THE SCHEDULING CONTRACT. The client schedules its
 * toast from `effective_seconds_remaining` (server-computed, immune to client
 * clock skew); the ISO deadlines are informational. `idle` is always the full
 * budget at response time — this request itself just touched the clock.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionDeadlineController extends Controller
{
    public function __construct(private readonly SessionLifetimePolicy $policy) {}

    public function show(Request $request): JsonResponse
    {
        $session = $request->session();
        $now = CarbonImmutable::now();

        $lastActivity = $this->timestamp($session->get(SessionLifetimePolicy::LAST_ACTIVITY_AT)) ?? $now;
        $established = $this->timestamp($session->get(SessionLifetimePolicy::ESTABLISHED_AT)) ?? $now;

        $idleDeadline = $lastActivity->addMinutes($this->policy->idleMinutes());
        $absoluteDeadline = $established->addHours($this->policy->absoluteHours());

        $idleRemaining = max(0, $now->diffInSeconds($idleDeadline, false));
        $absoluteRemaining = max(0, $now->diffInSeconds($absoluteDeadline, false));

        return new JsonResponse([
            'idle_deadline' => $idleDeadline->toIso8601String(),
            'absolute_deadline' => $absoluteDeadline->toIso8601String(),
            'effective_seconds_remaining' => (int) min($idleRemaining, $absoluteRemaining),
        ]);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
