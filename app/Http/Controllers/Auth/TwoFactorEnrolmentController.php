<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\TwoFactor\EnrolmentSuperseded;
use App\Domain\Auth\TwoFactor\InvalidEnrolmentCode;
use App\Domain\Auth\TwoFactor\TotpVerifier;
use App\Domain\Auth\TwoFactor\TwoFactorAlreadyEnabled;
use App\Domain\Auth\TwoFactor\TwoFactorEnrolment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Sessions\SessionRevoker;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service 2FA on the Security page (Doc 02 FR-06, FR-07 banner, FR-14).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EVERY MUTATION HERE EITHER STARTS 2FA OR DEMANDS A CURRENT CODE. begin() is
 * only reachable while 2FA is off (the service refuses otherwise); disable and
 * code regeneration require a code minted by the enrolled authenticator NOW.
 * The threat is not the user — it is whoever else is holding their session,
 * and "any 2FA change" is exactly what such a person wants to make quietly.
 * FR-14 then revokes every OTHER session on any change that goes through, so
 * even the sibling session a hijacker keeps open dies with the change.
 *
 * Self-service: every action operates on the AUTHENTICATED user; no parameter
 * names anyone else (see ci/permission-exempt-routes.php).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TwoFactorEnrolmentController extends Controller
{
    public function __construct(
        private readonly TwoFactorEnrolment $enrolment,
        private readonly TotpVerifier $verifier,
        private readonly SessionRevoker $revoker,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        try {
            $begun = $this->enrolment->begin($user, $this->issuerFor($user));
        } catch (TwoFactorAlreadyEnabled) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.2fa.already_enabled'),
            );
        }

        return new JsonResponse([
            'secret' => $begun['secret'],
            'otpauth_uri' => $begun['otpauth_uri'],
            'qr_svg' => $begun['qr_svg'],
            'enrolment_id' => $begun['enrolment_id'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user();
        $input = $this->validated($request, [
            'code' => ['required', 'string', 'max:16'],
            'enrolment_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $codes = $this->enrolment->confirm(
                $user,
                $input['code'],
                $input['enrolment_id'],
                CarbonImmutable::now(),
            );
        } catch (EnrolmentSuperseded) {
            // EC-10's DESIGNED loser error: another tab began a newer
            // enrolment, and this tab's QR no longer means anything.
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 409,
                detail: __('auth.2fa.enrolment_superseded'),
            );
        } catch (InvalidEnrolmentCode) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.failed.2fa_failed'),
            );
        }

        $this->revokeOtherSessions($request, $user);

        // SEC-03: the ONLY response that ever carries these codes.
        return new JsonResponse(['status' => 'enabled', 'recovery_codes' => $codes]);
    }

    public function regenerateCodes(Request $request): JsonResponse
    {
        $user = $this->user();
        $input = $this->validated($request, ['code' => ['required', 'string', 'max:16']]);

        if (! $this->presentedCodeIsValid($user, $input['code'])) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.failed.2fa_failed'),
            );
        }

        $codes = $this->enrolment->regenerateRecoveryCodes($user, CarbonImmutable::now());

        // FR-14: a fresh batch is a 2FA change like any other.
        $this->revokeOtherSessions($request, $user);

        return new JsonResponse(['status' => 'regenerated', 'recovery_codes' => $codes]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user();
        $input = $this->validated($request, ['code' => ['required', 'string', 'max:16']]);

        if (! $this->presentedCodeIsValid($user, $input['code'])) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.failed.2fa_failed'),
            );
        }

        $this->enrolment->disable($user, CarbonImmutable::now());
        $this->revokeOtherSessions($request, $user);

        return new JsonResponse(['status' => 'disabled']);
    }

    private function presentedCodeIsValid(User $user, string $code): bool
    {
        if ($user->getAttribute('two_factor_confirmed_at') === null) {
            return false; // nothing is enabled, so no change can be proven
        }

        $secret = $this->enrolment->secretFor($user);

        return $secret !== null && $this->verifier->verify($secret, $code, CarbonImmutable::now());
    }

    private function revokeOtherSessions(Request $request, User $user): void
    {
        $this->revoker->revokeAllFor(
            $user->tenantId(),
            $user->identifier(),
            reason: 'two_factor_changed',
            exceptSessionId: $request->session()->getId(),
            actorId: $user->identifier(),
        );
    }

    /**
     * The issuer an authenticator app displays (otpauth `issuer`). The tenant's
     * name IS the operator's brand here — white-label means the platform's own
     * name never appears in a player-or-staff-facing surface (N-03's spirit).
     */
    private function issuerFor(User $user): string
    {
        $tenant = $user->tenant()->first();
        $name = $tenant?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Support';
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, string>
     */
    private function validated(Request $request, array $rules): array
    {
        $validated = $request->validate($rules);

        /** @var array<string, string> */
        return is_array($validated) ? array_map(static fn ($v): string => is_string($v) ? $v : '', $validated) : [];
    }

    private function user(): User
    {
        $user = auth('staff')->user();

        if (! $user instanceof User) {
            throw new \LogicException('2FA routes require the staff guard; no principal is present.');
        }

        return $user;
    }
}
