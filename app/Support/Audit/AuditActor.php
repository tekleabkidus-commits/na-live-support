<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Who performed an audited action.
 *
 * Mirrors the `audit_log_actor_type_check` CHECK constraint and the
 * `actor_types` field in registries/audit-coverage.php. Three spellings of the
 * same four values, because the database must be able to reject a bad actor type
 * without asking PHP, and the registry must be readable by CI without booting the
 * application.
 *
 * `PlatformAdmin` is distinct from `System` on purpose: SEC-02 requires that
 * platform_admin use be logged WITH ACTOR IDENTITY, so an action taken by a human
 * on the platform console must never be indistinguishable from a cron job.
 */
enum AuditActor: string
{
    case Staff = 'staff';
    case Customer = 'customer';
    case System = 'system';
    case PlatformAdmin = 'platform_admin';
}
