<?php

declare(strict_types=1);

namespace App\Domain\Auth\Lockout;

use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;

/**
 * The transition rules (Doc 02 FR-03, BR-05, AC-02).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PURE FUNCTIONS OVER A STATE, WITH THE CLOCK PASSED IN.
 *
 * Nothing here reads `now()`, touches the database, or knows what a User is. That
 * is what makes FR-03's two time-dependent rules testable at their exact
 * boundaries instead of by sleeping:
 *
 *   the window ROLLS   — four failures spread over an hour must not accumulate
 *                        into a lock, or someone who mistypes once a month is
 *                        locked out by their own history.
 *   the lock EXPIRES   — after auth.lockout.duration, with nobody intervening.
 *
 * CLAUDE.md §8 forbids tests asserting on now(); this is the other half of that
 * rule — production code that cannot be given a clock forces the test to sleep,
 * and a suite that sleeps is a suite people stop running.
 *
 * WHY THE SETTINGS COME FROM THE REGISTRY DEFAULTS, NOT THE TENANT.
 * Lockout is evaluated on the login path, where no tenant is bound — finding the
 * account is what discovers the tenant. Reading a per-tenant override would be
 * impossible there, and worse, it would make the FIRST failed attempt reveal
 * which tenant's policy applied. FR-03's values are tenant-tunable in the
 * registry for a later step to apply once a tenant IS known (an admin changing
 * their own policy); the pre-auth evaluation uses platform defaults, which
 * BR-05's validation floors already guarantee cannot be weakened.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LockoutPolicy
{
    public function __construct(private readonly SettingsRegistry $registry) {}

    /**
     * Record one failed attempt and return the resulting state.
     */
    public function recordFailure(LockoutState $state, CarbonImmutable $now): LockoutState
    {
        // An already-locked account does not accumulate further failures: the
        // lock is the outcome, and counting past it would let an attacker extend
        // someone else's lockout indefinitely by keeping the attempts coming.
        if ($state->isLockedAt($now)) {
            return $state;
        }

        $windowOpenedAt = $state->firstFailureAt;
        $attempts = $state->failedAttempts;

        // Roll: if the window has expired (or never opened), this failure starts
        // a fresh one rather than adding to a stale count.
        if ($windowOpenedAt === null || $windowOpenedAt->addMinutes($this->windowMinutes())->lessThanOrEqualTo($now)) {
            $windowOpenedAt = $now;
            $attempts = 0;
        }

        $attempts++;

        if ($attempts >= $this->maxAttempts()) {
            return new LockoutState(
                failedAttempts: $attempts,
                firstFailureAt: $windowOpenedAt,
                lockedUntil: $now->addMinutes($this->durationMinutes()),
            );
        }

        return new LockoutState($attempts, $windowOpenedAt, null);
    }

    /**
     * A successful authentication clears the window (FR-03's implicit rule: the
     * counter is about consecutive failures, not lifetime failures).
     */
    public function recordSuccess(): LockoutState
    {
        return LockoutState::fresh();
    }

    /**
     * An explicit unlock — admin action or a completed password reset (FR-03,
     * EC-11). Identical state to a success; the DIFFERENCE lives in the audit
     * entry the manager writes, which is exactly why lockout is a state machine
     * with named transitions rather than a counter someone zeroes.
     */
    public function unlock(): LockoutState
    {
        return LockoutState::fresh();
    }

    public function maxAttempts(): int
    {
        return $this->intSetting('auth.lockout.attempts', 5);
    }

    public function windowMinutes(): int
    {
        return $this->intSetting('auth.lockout.window', 15);
    }

    public function durationMinutes(): int
    {
        return $this->intSetting('auth.lockout.duration', 15);
    }

    private function intSetting(string $key, int $fallback): int
    {
        $default = $this->registry->definition($key)['default'];

        return is_numeric($default) ? (int) $default : $fallback;
    }
}
