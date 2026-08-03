<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Lockout\LockoutManager;
use App\Domain\Auth\Login\FailureReason;
use App\Domain\Auth\Login\LoginRecorder;
use App\Domain\Auth\Login\LoginRefused;
use App\Domain\Auth\Login\Pipes\RefuseBlockedPrincipal;
use App\Domain\Auth\TwoFactor\PendingChallenge;
use App\Domain\Auth\TwoFactor\RecoveryCodeStore;
use App\Domain\Auth\TwoFactor\TotpVerifier;
use App\Domain\Auth\TwoFactor\TwoFactorEnrolment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Sessions\SessionEstablisher;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Tenancy\RunsWithinTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;

/**
 * The 2FA challenge — the second half of a 2FA login (Doc 02 FR-07, AC-03, §10).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHO IS TALKING TO THIS ROUTE: someone whose PASSWORD verified moments ago and
 * whose session carries the pending-challenge record. Nothing else — no guard
 * user exists until the code verifies (SS-02). The route sits in the same
 * guest+throttle group as login itself: the per-IP limiter caps code guessing
 * exactly as it caps password guessing.
 *
 * TWO PROOFS, ONE DOOR. A TOTP (verified against the enrolled secret, ±1 step,
 * clock injected) or a recovery code (consumed from the hashed store, single
 * use). FR-07's counter spans both: five failures of any mix void the login
 * attempt, feed ONE failure to lockout, and send the caller back to the
 * password.
 *
 * BLOCKED-PRINCIPAL CHECKS RUN THROUGH THE SAME PIPE AS LOGIN. After the code
 * verifies, the user is logged in and RefuseBlockedPrincipal re-checks
 * disabled/suspended exactly as the password door does — one implementation,
 * not a paraphrase, because a paraphrase is where the two doors would drift
 * apart. Racing states (locked mid-challenge) are refused before any
 * verification with the same generic copy the login uses.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TwoFactorChallengeController extends Controller
{
    use RunsWithinTenant;

    public function __construct(
        private readonly PendingChallenge $pending,
        private readonly TwoFactorEnrolment $enrolment,
        private readonly TotpVerifier $verifier,
        private readonly RecoveryCodeStore $recoveryCodes,
        private readonly LockoutManager $lockout,
        private readonly LoginRecorder $recorder,
        private readonly SessionEstablisher $establisher,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $pending = $this->pending->current($request);

        if ($pending === null) {
            // No password went before this code. Not an attack necessarily —
            // an expired session or a bookmarked URL — but nothing to verify
            // against either way. `restart_login` is an RFC 9457 extension
            // member (step 13): the DETAIL is localized prose the login card
            // must not branch on, and this flag is the stable "back to the
            // password step" signal §10's diagram draws. It reveals nothing —
            // the caller already knows no challenge is open.
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.2fa.no_pending'),
                extra: ['restart_login' => true],
            );
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:16'],
        ]);
        $input = is_array($validated) ? $validated : [];
        $code = is_string($input['code'] ?? null) && $input['code'] !== '' ? $input['code'] : null;
        $recoveryCode = is_string($input['recovery_code'] ?? null) && $input['recovery_code'] !== '' ? $input['recovery_code'] : null;

        if (($code === null) === ($recoveryCode === null)) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.2fa.one_proof_required'),
            );
        }

        /** @var User|null $user */
        $user = $this->withinTenant(
            $pending['tenant_id'],
            fn (): ?User => User::query()->find($pending['user_id']),
        );

        $now = CarbonImmutable::now();

        if (! $user instanceof User) {
            // The account vanished between password and code. Void and render
            // the one generic credential failure (AC-01's shape).
            $this->pending->clear($request);

            throw new LoginRefused(FailureReason::BadCredentials);
        }

        if ($this->isLockedAt($user, $now)) {
            // Locked WHILE the challenge was open — the lockout transition was
            // audited when it happened; this is only its refusal.
            $this->pending->clear($request);

            throw new LoginRefused(FailureReason::Locked);
        }

        $extraPayload = [];

        if ($code !== null) {
            $secret = $this->enrolment->secretFor($user);
            $verified = $secret !== null && $this->verifier->verify($secret, $code, $now);
        } else {
            $verified = (bool) $this->withinTenant(
                $pending['tenant_id'],
                fn (): bool => $this->recoveryCodes->consume($user, (string) $recoveryCode, $now),
            );

            if ($verified) {
                // FR-07: "consumes one code and shows remaining count"; the ≤2
                // flag is R-4's banner trigger, computed from the store.
                $remaining = (int) $this->withinTenant(
                    $pending['tenant_id'],
                    fn (): int => $this->recoveryCodes->remaining($user),
                );

                $extraPayload = [
                    'recovery_codes_remaining' => $remaining,
                    'recovery_codes_low' => $remaining <= RecoveryCodeStore::REGENERATION_PROMPT_AT,
                ];
            }
        }

        if (! $verified) {
            $attempt = $this->pending->recordFailure($request);
            $this->recorder->twoFactorChallengeFailed($user, $attempt, $request);

            if ($attempt >= PendingChallenge::MAX_ATTEMPTS) {
                // FR-07: the login attempt is VOIDED — back to the password —
                // and the void counts toward lockout as one failure.
                $this->pending->clear($request);
                $this->lockout->recordVoidedChallenge($user, $now);

                // Same extension member as the no-pending answer: FR-07's void
                // sends the caller BACK TO THE PASSWORD, and the card needs a
                // stable signal to do that (the localized detail is not one).
                return ProblemDetails::response(
                    code: ErrorCode::InvalidCredentials,
                    status: 422,
                    detail: __('auth.2fa.voided'),
                    extra: ['restart_login' => true],
                );
            }

            throw new LoginRefused(FailureReason::TwoFactorFailed);
        }

        $this->pending->clear($request);

        // The whole completion runs WITH THE TENANT BOUND. At the password
        // door the provider binds it as a side effect of resolution; this door
        // authenticated via a code, so the binding is explicit — without it the
        // blocked-principal pipe cannot read the tenant row and the
        // establisher's audit writes fail behind RLS.
        $result = $this->withinTenant($pending['tenant_id'], function () use ($request, $user, $pending, $extraPayload): mixed {
            // Authentication is now COMPLETE; the withheld remember flag is
            // honoured here and nowhere earlier (FR-13).
            auth('staff')->login($user, $pending['remember']);

            return app(Pipeline::class)
                ->send($request)
                ->through([RefuseBlockedPrincipal::class])
                ->then(fn (Request $request): JsonResponse|RedirectResponse => $this->establisher->establish($request, $user, $extraPayload));
        });

        if (! $result instanceof JsonResponse && ! $result instanceof RedirectResponse) {
            throw new \LogicException('The challenge completion pipeline returned no response.');
        }

        return $result;
    }

    private function isLockedAt(User $user, CarbonImmutable $now): bool
    {
        $lockedUntil = $user->getAttribute('locked_until');

        $lockedUntil = match (true) {
            $lockedUntil instanceof CarbonImmutable => $lockedUntil,
            $lockedUntil instanceof \DateTimeInterface => CarbonImmutable::instance($lockedUntil),
            is_string($lockedUntil) && $lockedUntil !== '' => CarbonImmutable::parse($lockedUntil),
            default => null,
        };

        return $lockedUntil !== null && $lockedUntil->greaterThan($now);
    }
}
