<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

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
 * FR-08: an admin clears a user's 2FA (US-02's "lost my phone" path).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE TYPED-NAME CONFIRMATION IS SERVER-ENFORCED, not a front-end nicety: the
 * request must carry the target's exact name, so a mis-clicked row in the
 * admin UI cannot silently strip someone's second factor. High-visibility
 * audit (`auth.2fa.reset_by_admin`, admin recorded as actor) is the §4.8
 * counterweight to how much power this route holds; the permission
 * `admin.2fa.reset` is DECLARED here and enforced when Doc 03's resolver
 * lands (RequirePermission's documented seam).
 *
 * The target's sessions are ALL revoked — including whatever session a phone
 * thief might be holding, which is half the reason the reset exists. The
 * target logs in with password alone next time and FR-09's enforcement (once
 * built) walks them straight back into enrolment.
 *
 * Tenant scoping is structural: the lookup runs under the admin's own bound
 * tenant, so another tenant's user id resolves to nothing and 404s (C-10).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TwoFactorAdminResetController extends Controller
{
    public function __construct(
        private readonly TwoFactorEnrolment $enrolment,
        private readonly SessionRevoker $revoker,
    ) {}

    public function destroy(Request $request, string $userId): JsonResponse
    {
        $admin = auth('staff')->user();

        if (! $admin instanceof User) {
            throw new \LogicException('Admin 2FA reset requires the staff guard; no principal is present.');
        }

        $validated = $request->validate(['confirm_name' => ['required', 'string', 'max:255']]);
        $input = is_array($validated) ? $validated : [];
        $confirmName = is_string($input['confirm_name'] ?? null) ? $input['confirm_name'] : '';

        /** @var User|null $target */
        $target = User::query()->find($userId);

        if (! $target instanceof User) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 404,
                detail: __('auth.2fa.user_not_found'),
            );
        }

        $targetName = $target->getAttribute('name');
        if (! is_string($targetName) || ! hash_equals($targetName, $confirmName)) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.2fa.confirm_name_mismatch'),
            );
        }

        if ($target->getAttribute('two_factor_confirmed_at') === null) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: __('auth.2fa.not_enabled_for_user'),
            );
        }

        $this->enrolment->adminReset($target, $admin, CarbonImmutable::now());

        // ALL of the target's sessions die — no except-current here; the
        // current session being an attacker's is the scenario US-03/US-02
        // describe.
        $this->revoker->revokeAllFor(
            $target->tenantId(),
            $target->identifier(),
            reason: 'two_factor_reset_by_admin',
            actorId: $admin->identifier(),
        );

        return new JsonResponse(['status' => 'reset']);
    }
}
