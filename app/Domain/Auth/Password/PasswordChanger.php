<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Domain\Auth\Lockout\LockoutManager;
use App\Models\User;
use App\Support\Auth\Sessions\SessionRevoker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Everything that happens the moment a password is APPLIED
 * (Doc 02 FR-14, FR-15, FR-16, FR-17; rulings R-6).
 *
 * Extracted for the same reason SessionEstablisher was: three doors lead here —
 * the reset form, the authenticated self-change, the invitation acceptance —
 * and the sequence must stay identical from every one of them. Policy, then
 * hash, then history, then revocation, then the door-specific unlock, then the
 * record. A second hand-rolled copy in one of the controllers would be the
 * copy that forgets the history write, and a forgotten history write is
 * invisible until someone reuses a password it should have remembered.
 *
 * ORDERING NOTES THAT ARE CONTRACTS, NOT STYLE:
 *
 *   policy BEFORE anything mutates — a rejected candidate must leave zero
 *   trace: no hash change, no history row, no revoked session.
 *
 *   history remembers the PRE-change hash, captured before the model's
 *   `hashed` cast replaces it. After save() the model no longer knows it.
 *
 *   revocation AFTER save — the moment sessions die, the new credential must
 *   already be the one that works. The reverse order has a window where the
 *   old password is the only way back in and its sessions are already gone.
 *
 *   unlock LAST and only for Reset (R-6): exactly the account the token was
 *   bound to. Sibling candidates on the same address stay locked, and nothing
 *   here touches the per-IP throttle.
 */
final class PasswordChanger
{
    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly PasswordHistory $history,
        private readonly SessionRevoker $revoker,
        private readonly LockoutManager $lockout,
        private readonly PasswordLifecycleRecorder $recorder,
    ) {}

    /**
     * Apply a new password through one of the three doors. Call with the
     * user's tenant bound; throws PasswordPolicyViolation with per-rule errors
     * and mutates nothing when the candidate fails policy.
     *
     * @param  string|null  $keepSessionId  SelfChange only: the session that
     *                                      proved the current password survives
     *                                      (FR-14 revokes the OTHERS)
     * @param  (callable(): bool)|null  $oncePolicyPassed  the token-consumption
     *                                      gate: runs AFTER policy passes and
     *                                      BEFORE anything mutates, so a policy
     *                                      failure leaves the caller's token
     *                                      alive for the retry, while a consumed
     *                                      token binds to exactly one mutation.
     *                                      Returning false (someone else spent
     *                                      it first) aborts with nothing changed.
     */
    public function apply(
        User $user,
        string $plaintext,
        PasswordChangeOrigin $origin,
        CarbonImmutable $now,
        Request $request,
        ?string $keepSessionId = null,
        ?callable $oncePolicyPassed = null,
    ): void {
        $this->policy->enforce($user, $plaintext);

        if ($oncePolicyPassed !== null && $oncePolicyPassed() === false) {
            throw new StaleActionToken;
        }

        $previousHash = (string) $user->getRawOriginal('password');
        $hadPassword = $user->getAttribute('password_set_at') !== null;

        // The `hashed` cast runs argon2id at NFR-03's tuning; assigning an
        // already-hashed value here would be the double-hash bug, so the model
        // receives the plaintext exactly once and nothing else ever does.
        $user->setAttribute('password', $plaintext);
        $user->setAttribute('password_changed_at', $now);

        if (! $hadPassword) {
            // FR-17: the moment an invited account stops being invited.
            $user->setAttribute('password_set_at', $now);
        }

        $user->save();

        if ($hadPassword) {
            $this->history->remember($user, $previousHash);
        }

        $this->revoker->revokeAllFor(
            $user->tenantId(),
            $user->identifier(),
            reason: 'password_'.$origin->value,
            exceptSessionId: $origin === PasswordChangeOrigin::SelfChange ? $keepSessionId : null,
            actorId: $user->identifier(),
        );

        if ($origin === PasswordChangeOrigin::Reset) {
            // FR-03/EC-11 via R-6: the victim's guaranteed way back, for THIS
            // account alone.
            $this->lockout->unlockAfterPasswordReset($user);
        }

        $this->recorder->passwordApplied($user, $origin, $request);
    }
}
