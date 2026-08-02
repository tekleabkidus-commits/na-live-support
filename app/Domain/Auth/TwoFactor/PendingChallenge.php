<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The half-authenticated state between password and TOTP (Doc 02 FR-07, §10).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS STATE IS AND IS NOT. After the password verifies, a 2FA user is
 * NOT authenticated: no guard user, no session auth key, no tenant hint, and
 * the session stays in the GUEST keyspace. What the session carries is only
 * this pending record — who passed the password, which workspace they resolved
 * to, whether they asked to be remembered, and how many challenge attempts
 * they have burned. §10's diagram is explicit that the session comes AFTER the
 * 2FA branch; SS-02 is the acceptance shape ("valid-password-no-TOTP attacker
 * establishes zero session state and reads zero data").
 *
 * THE ATTEMPT COUNTER LIVES HERE, IN THE SESSION, DELIBERATELY. FR-07's five
 * attempts belong to THIS login attempt — voiding wipes the state, and a fresh
 * password entry starts a fresh challenge with fresh attempts. The durable,
 * cross-attempt protection is the lockout counter (the void feeds it one
 * failure) and the per-IP throttle on the challenge route; an attacker who
 * discards cookies to reset THIS counter still walks into both.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PendingChallenge
{
    private const USER_ID = '2fa.pending_user_id';

    private const TENANT_ID = '2fa.pending_tenant_id';

    private const REMEMBER = '2fa.pending_remember';

    private const ATTEMPTS = '2fa.pending_attempts';

    public const MAX_ATTEMPTS = 5; // FR-07

    public function stash(Request $request, User $user, bool $remember): void
    {
        // SEC-04's first boundary applies to this path too: the password was
        // just accepted, so a planted pre-login id must die BEFORE any
        // privileged state (even half-privileged state) attaches to a session.
        $request->session()->regenerate();

        $request->session()->put([
            self::USER_ID => $user->identifier(),
            self::TENANT_ID => $user->tenantId(),
            self::REMEMBER => $remember,
            self::ATTEMPTS => 0,
        ]);
    }

    /**
     * @return array{user_id: string, tenant_id: string, remember: bool, attempts: int}|null
     */
    public function current(Request $request): ?array
    {
        $userId = $request->session()->get(self::USER_ID);
        $tenantId = $request->session()->get(self::TENANT_ID);

        if (! is_string($userId) || $userId === '' || ! is_string($tenantId) || $tenantId === '') {
            return null;
        }

        $attempts = $request->session()->get(self::ATTEMPTS);

        return [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'remember' => (bool) $request->session()->get(self::REMEMBER, false),
            'attempts' => is_int($attempts) ? $attempts : 0,
        ];
    }

    /**
     * @return int the attempt count after this failure
     */
    public function recordFailure(Request $request): int
    {
        $attempts = $request->session()->get(self::ATTEMPTS);
        $attempts = (is_int($attempts) ? $attempts : 0) + 1;

        $request->session()->put(self::ATTEMPTS, $attempts);

        return $attempts;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget([self::USER_ID, self::TENANT_ID, self::REMEMBER, self::ATTEMPTS]);
    }
}
