<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Who is allowed to bypass tenant isolation (Doc 01 SEC-02).
 *
 * SEC-02 names exactly two consumers of the platform_admin role: "the platform
 * console service account and retention jobs". This enum is that sentence,
 * enforced — a caller cannot invent a third by passing a free-form string, and
 * adding a case is a visible change in a file whose whole purpose is to be short.
 *
 * The value ends up in the log line and in audit_log.actor_label, so "who read
 * across tenants" has a machine-readable answer.
 */
enum PlatformActor: string
{
    /** The platform console (Doc 01 §8): tenant list, create, state, quotas. */
    case PlatformConsole = 'platform_console';

    /** Retention and partition maintenance (NFR-07, EC-10). */
    case RetentionJob = 'retention_job';

    /** Quota counter reconciliation across all tenants (FS-05). */
    case ReconciliationJob = 'reconciliation_job';
}
