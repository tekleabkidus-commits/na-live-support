<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Provisioning;

/**
 * The provisioning steps, in order (Doc 01 FR-06).
 *
 * FR-06 lists exactly what provisioning creates: "tenant row, default settings set, default
 * roles (from Doc 03 seed), default SLA policy, default business hours, empty KB with starter
 * templates, audit log entry tenant.provisioned".
 *
 * Each is a separate step with its own row in `tenant_provisioning_steps`, and that
 * granularity is the requirement rather than an implementation taste:
 *
 *   EC-05  the idempotency key is "tenant id + step", so steps must be individually
 *          identifiable for a retry to skip what already happened.
 *   FS-04  a failed step is recorded as failed with an error reference and the tenant stays
 *          `provisioning`, so §9's console can show WHICH step failed and offer Retry.
 *   EC-04  state is re-checked BETWEEN steps, so a suspension mid-flight halts cleanly at a
 *          step boundary rather than half-way through one.
 *
 * THREE OF THESE ARE SEAMS, NOT IMPLEMENTATIONS. Roles belong to Doc 03, the SLA policy to
 * Doc 12, and the knowledge base to Doc 10. Doc 01 owns the ORDER and the idempotency, so the
 * steps exist and are recorded as completed with nothing to create yet. That is deliberate: if
 * the steps were added later, the sequencing and the retry semantics would be retrofitted onto
 * a job that had never needed them, and FS-04's "never partially active" would be a claim
 * rather than a mechanism.
 */
enum ProvisioningStep: string
{
    /** The tenant row itself; created by the provisioner before any step runs. */
    case TenantRecord = 'tenant_record';

    /** FR-07's registered defaults, materialised as explicit overrides where a tenant needs them. */
    case DefaultSettings = 'default_settings';

    /** SEAM: Doc 03 seeds the default role set. */
    case DefaultRoles = 'default_roles';

    /**
     * Doc 11 FR-01 (S11 STOP-C.1 packet, countersigned 2026-08-30): the seeded department set,
     * each with one default queue (OrganizationSeeder). Runs AFTER roles and BEFORE SLA/hours
     * so the tenant default department EXISTS before the tenant is Active — FR-08 step 1's
     * terminal fallback is never absent for the first ticket.
     */
    case Organization = 'organization';

    /** SEAM: Doc 12 owns SLA policies. */
    case SlaPolicy = 'sla_policy';

    /** SEAM: Doc 12 owns business hours. */
    case BusinessHours = 'business_hours';

    /** SEAM: Doc 10 owns the knowledge base and its starter templates. */
    case KnowledgeBase = 'knowledge_base';

    /**
     * Every step, in the order FR-06 lists them.
     *
     * Order matters for one reason beyond tidiness: settings come before the seams, so a
     * later module's seeder can read a tenant's configuration rather than assuming defaults.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::TenantRecord,
            self::DefaultSettings,
            self::DefaultRoles,
            self::Organization,
            self::SlaPolicy,
            self::BusinessHours,
            self::KnowledgeBase,
        ];
    }

    /** The i18n key for §8's per-step progress screen (N-03/C-08). */
    public function labelI18nKey(): string
    {
        return sprintf('foundation.provisioning.step.%s', $this->value);
    }

    /**
     * True while no module owns this step's contents yet.
     *
     * Reported by the provisioner so a completed-but-empty step is visible as such, rather
     * than looking like work that happened. An operator's console showing "Setting up roles ✓"
     * when no roles exist would be a lie told by a progress bar.
     */
    public function isSeamAwaitingItsModule(): bool
    {
        // DISCLOSED RIDER (S11 STOP-C.1 packet): DefaultRoles (Doc 03, S19) and KnowledgeBase
        // (Doc 10, S10) are FILLED seams that still reported `true` here — a pre-existing
        // staleness corrected in passing, its own line in the commit message.
        return match ($this) {
            self::SlaPolicy, self::BusinessHours => true,
            self::TenantRecord, self::DefaultSettings, self::DefaultRoles, self::Organization, self::KnowledgeBase => false,
        };
    }

    /** The document that will fill this seam in. */
    public function owningDoc(): string
    {
        return match ($this) {
            self::TenantRecord, self::DefaultSettings => 'Doc01',
            self::DefaultRoles => 'Doc03',
            self::Organization => 'Doc11',
            self::SlaPolicy, self::BusinessHours => 'Doc12',
            self::KnowledgeBase => 'Doc10',
        };
    }
}
