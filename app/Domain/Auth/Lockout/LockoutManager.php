<?php

declare(strict_types=1);

namespace App\Domain\Auth\Lockout;

use App\Domain\Auth\Login\StaffLoginCandidate;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies lockout transitions and records them (Doc 02 FR-03, AC-02, EC-11, R-04).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS BESIDE LockoutPolicy: the policy decides, this one persists and
 * audits. Splitting them is what makes AC-02's "admin unlock audited" clause
 * expressible — an unlock and an expiry produce the SAME state, and only the
 * transition that reached it distinguishes them. A counter cannot record that.
 *
 * THE MULTI-CANDIDATE RULE (owner ruling, Q1 extension). A failed attempt for an
 * address counts against EVERY account that address matches, across tenants. The
 * reasoning is worth keeping: the attacker is guessing an ADDRESS, and the
 * platform cannot know which workspace they meant. Counting against one account
 * would leave the others unprotected, and — worse — WHICH account locked would
 * tell the attacker which tenant they had hit. Each account then recovers
 * independently through its own password reset (EC-11).
 *
 * TENANT BINDING IS PER CANDIDATE, and unavoidable: these rows live in different
 * tenants, each behind RLS, so the write for each candidate happens inside that
 * candidate's own bound context. The context is restored afterwards so the login
 * path continues exactly as it was — leaving a stray binding here would be the
 * R-01 failure mode Doc 01 warns is invisible in a green run.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LockoutManager
{
    use RunsWithinTenant;

    public function __construct(
        private readonly LockoutPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
    ) {}

    /**
     * Record a failed attempt against every candidate for the address.
     *
     * @param  list<StaffLoginCandidate>  $candidates
     */
    public function recordFailedAttempt(array $candidates, CarbonImmutable $now): void
    {
        foreach ($candidates as $candidate) {
            $before = LockoutState::fromCandidate(
                $candidate->failedLoginAttempts,
                $candidate->firstFailedLoginAt,
                $candidate->lockedUntil,
            );

            $after = $this->policy->recordFailure($before, $now);

            // Value comparison, deliberately not identity: recordFailure()
            // always returns a NEW object, so `===` would be false every time
            // and an already-locked account would be rewritten on every attempt.
            if ($after === $before) {
                continue;
            }

            $this->withinTenant($candidate->tenantId, function () use ($candidate, $before, $after): void {
                $this->persist($candidate->userId, $after);

                // Audit the TRANSITION INTO the locked state, once — not every
                // failure. A per-failure entry would bury the one event a
                // security officer is looking for under four that precede it.
                if ($before->lockedUntil === null && $after->lockedUntil !== null) {
                    $this->audit->record(
                        actionKey: 'auth.lockout.locked',
                        entity: 'user',
                        entityId: $candidate->userId,
                        actorType: AuditActor::System,
                        payload: [
                            'reason' => 'failed_attempts',
                            'failed_attempts' => $after->failedAttempts,
                        ],
                        tenantId: $candidate->tenantId,
                    );
                }
            }, $this->context, $this->rls);
        }
    }

    /**
     * FR-07: a VOIDED 2FA challenge counts as ONE failed login attempt.
     *
     * The five wrong codes themselves are the challenge's own counter; what
     * feeds lockout is the void — the whole login attempt failing. Counting
     * each code here instead would let one mistyped-authenticator session lock
     * an account that never got a password wrong, which is a harsher machine
     * than AC-02 specifies.
     */
    public function recordVoidedChallenge(User $user, CarbonImmutable $now): void
    {
        $before = LockoutState::fromCandidate(
            $this->intAttribute($user, 'failed_login_attempts'),
            $this->timeAttribute($user, 'first_failed_login_at'),
            $this->timeAttribute($user, 'locked_until'),
        );

        $after = $this->policy->recordFailure($before, $now);

        if ($after === $before) {
            return;
        }

        $this->withinTenant($user->tenantId(), function () use ($user, $before, $after): void {
            $this->persist($user->identifier(), $after);

            if ($before->lockedUntil === null && $after->lockedUntil !== null) {
                $this->audit->record(
                    actionKey: 'auth.lockout.locked',
                    entity: 'user',
                    entityId: $user->identifier(),
                    actorType: AuditActor::System,
                    payload: [
                        'reason' => '2fa_challenge_voided',
                        'failed_attempts' => $after->failedAttempts,
                    ],
                    tenantId: $user->tenantId(),
                );
            }
        }, $this->context, $this->rls);
    }

    private function intAttribute(User $user, string $key): int
    {
        $value = $user->getAttribute($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function timeAttribute(User $user, string $key): ?CarbonImmutable
    {
        $value = $user->getAttribute($key);

        return match (true) {
            $value instanceof CarbonImmutable => $value,
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value),
            default => null,
        };
    }

    /**
     * A successful authentication ends the current failure window.
     */
    public function recordSuccess(User $user): void
    {
        if ($user->getAttribute('failed_login_attempts') === 0 && $user->getAttribute('locked_until') === null) {
            return; // nothing to clear; skip the write on the happy path
        }

        $this->persist($user->identifier(), $this->policy->recordSuccess());
    }

    /**
     * FR-03 / EC-11: a completed password reset unlocks the account.
     *
     * This is the victim's guaranteed way back when an attacker has locked them
     * out deliberately — it must never require the attacker to stop.
     */
    public function unlockAfterPasswordReset(User $user): void
    {
        $this->applyUnlock($user, 'password_reset', AuditActor::System);
    }

    /**
     * FR-03 / AC-02: an admin with the relevant permission unlocks an account.
     *
     * The actor is recorded, which is the clause AC-02 names explicitly and the
     * single strongest argument for this being a state machine.
     */
    public function unlockByAdmin(User $user, User $admin): void
    {
        $this->applyUnlock($user, 'admin_unlock', AuditActor::Staff, $admin->identifier());
    }

    private function applyUnlock(User $user, string $reason, AuditActor $actorType, ?string $actorId = null): void
    {
        $this->withinTenant($user->tenantId(), function () use ($user, $reason, $actorType, $actorId): void {
            $this->persist($user->identifier(), $this->policy->unlock());
            $this->writeUnlockAudit($user, $reason, $actorType, $actorId);
        }, $this->context, $this->rls);
    }

    private function writeUnlockAudit(User $user, string $reason, AuditActor $actorType, ?string $actorId): void
    {
        $this->audit->record(
            actionKey: 'auth.lockout.unlocked',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: $actorType,
            payload: ['reason' => $reason],
            actorId: $actorId,
            tenantId: $user->tenantId(),
        );
    }

    private function persist(string $userId, LockoutState $state): void
    {
        DB::table('users')->where('id', $userId)->update([
            'failed_login_attempts' => $state->failedAttempts,
            'first_failed_login_at' => $state->firstFailureAt,
            'locked_until' => $state->lockedUntil,
        ]);
    }
}
