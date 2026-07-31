<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login\Pipes;

use App\Domain\Auth\Login\FailureReason;
use App\Domain\Auth\Login\LoginRecorder;
use App\Domain\Auth\Login\LoginRefused;
use App\Domain\Auth\Login\StaffLoginCandidate;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * FR-05: a disabled user or a suspended tenant is blocked, with distinct,
 * non-technical messages — and both are audited.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS RUNS *AFTER* THE PASSWORD IS VERIFIED, WHICH LOOKS WASTEFUL.
 *
 * Checking "is this account disabled" before verifying the password would be
 * cheaper — no argon2id for an account that cannot log in anyway. It would also
 * be an enumeration oracle, and a particularly good one: "this account is
 * disabled" confirms the address exists to anyone who types it, with no password
 * needed at all. AC-01 forbids exactly that.
 *
 * So the order is: verify first (constant cost, generic failure), and only then
 * tell a person who has PROVEN they hold the credential why they still cannot
 * get in. FR-05's distinct messages are safe precisely because reaching them
 * requires the password.
 *
 * The same logic explains the lockout check's placement in the sibling pipe: a
 * locked account gets the generic message, because a locked-account notice
 * before verification would leak existence the same way.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RefuseBlockedPrincipal
{
    public function __construct(private readonly LoginRecorder $recorder) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user('staff') ?? auth('staff')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $candidate = $this->candidateFor($user);

        if ($user->isDisabled()) {
            $this->refuse($candidate, FailureReason::DisabledUser, $request);
        }

        $state = $this->tenantState($user);

        // FR-05 / EC-03: a suspended tenant's staff cannot sign in. The inbound
        // channels stay open (a suspended operator's players keep being heard);
        // it is the OPERATOR's own access that stops.
        if (! $state->allowsStaffLogin()) {
            $this->refuse($candidate, FailureReason::SuspendedTenant, $request);
        }

        return $next($request);
    }

    private function refuse(StaffLoginCandidate $candidate, FailureReason $reason, Request $request): never
    {
        $this->recorder->failedForKnownAccount($candidate, $reason, $request);

        // The session is torn down before the exception leaves: the credential
        // was correct, so a guard somewhere may already consider this request
        // authenticated, and a blocked principal must not keep a live session
        // for even one response (BR-04's spirit, EC-05's requirement).
        auth('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new LoginRefused($reason);
    }

    private function tenantState(User $user): TenantState
    {
        $tenant = $user->tenant()->first();

        // No tenant row readable means no basis to permit a login. Failing
        // closed here rather than defaulting to "active" is the same posture
        // Doc 01 takes everywhere: absence of proof is not proof.
        return $tenant === null
            ? TenantState::Suspended
            : $tenant->state();
    }

    private function intAttribute(User $user, string $key): int
    {
        $value = $user->getAttribute($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringAttribute(User $user, string $key): ?string
    {
        $value = $user->getAttribute($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function candidateFor(User $user): StaffLoginCandidate
    {
        // The pipeline has already bound the tenant, so the candidate is
        // reconstructed from the model rather than re-running the pre-tenant
        // lookup — one fewer read on the login path, and the same facts.
        return new StaffLoginCandidate(
            userId: $user->identifier(),
            tenantId: $user->tenantId(),
            passwordHash: '',
            disabled: $user->isDisabled(),
            lockedUntil: null,
            failedLoginAttempts: $this->intAttribute($user, 'failed_login_attempts'),
            firstFailedLoginAt: null,
            twoFactorEnabled: $user->hasTwoFactorEnabled(),
            passwordSet: $user->getAttribute('password_set_at') !== null,
            locale: $this->stringAttribute($user, 'locale'),
            tenantState: $this->tenantState($user),
        );
    }
}
