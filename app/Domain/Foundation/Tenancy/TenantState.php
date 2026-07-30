<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenancy;

/**
 * The tenant lifecycle states (Doc 01 FR-05).
 *
 * Four states, and the same four appear in three places: this enum, the
 * `tenants_state_check` CHECK constraint, and the transition trigger. That is
 * duplication on purpose — the database must be able to reject a bad state
 * without asking PHP — and tests/Feature/Foundation/TenantLifecycleTest asserts
 * the three agree, so they cannot drift.
 *
 * The behavioural questions each state answers are methods here rather than
 * conditionals at call sites, so that FR-05's matrix is written once and AC-08
 * can test it directly instead of testing five surfaces' opinions of it.
 */
enum TenantState: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * May staff sign in?
     *
     * FR-05: suspended blocks staff logins. Provisioning does too — there is
     * nothing to sign into until defaults exist, and §8 shows a progress screen
     * instead. Archived is export-only, for platform admins, so not a staff login
     * either.
     */
    public function allowsStaffLogin(): bool
    {
        return $this === self::Active;
    }

    /**
     * May inbound channel traffic be accepted?
     *
     * TRUE for suspended, and this is the subtle heart of FR-05. A suspended
     * operator is usually a payment problem, not a closed business: US-02 says
     * "I can suspend a tenant knowing inbound player messages are still being
     * captured, so reactivation loses nothing". So we keep ACCEPTING and QUEUEING
     * inbound messages while refusing to send anything out. Dropping them would
     * lose a player's question through no fault of the player.
     *
     * Archived is the end of the relationship: keys are revoked, so nothing
     * reaches this check.
     */
    public function acceptsInboundTraffic(): bool
    {
        return $this === self::Active || $this === self::Suspended;
    }

    /**
     * May anything be sent to a customer?
     *
     * FR-05: suspended means "no outbound sends". The asymmetry with
     * acceptsInboundTraffic() is the entire point — a suspended operator must not
     * appear to be operating, while nothing the player sends is lost.
     */
    public function allowsOutboundSends(): bool
    {
        return $this === self::Active;
    }

    /** FR-05: a non-dismissable banner on any permitted surface. */
    public function requiresBanner(): bool
    {
        return $this === self::Suspended || $this === self::Provisioning;
    }

    /** FR-05: archived is read-only export for platform admins only. */
    public function isReadOnly(): bool
    {
        return $this === self::Archived;
    }

    /**
     * The i18n key for this state's banner (C-08/N-03 — never literal copy).
     */
    public function bannerI18nKey(): string
    {
        return sprintf('foundation.tenant.banner.%s', $this->value);
    }

    /**
     * States reachable from here (FR-05, EC-04).
     *
     * Mirrors the trigger in
     * database/migrations/*_create_tenant_state_transition_guard.php.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Provisioning => [self::Active, self::Suspended],
            self::Active => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
