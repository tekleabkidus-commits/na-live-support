<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use App\Support\Audit\AuditActor;

/**
 * FR-04's four actor classes: "Every transition records actor (user/ai/automation/
 * system)".
 *
 * WHY THIS DECIDES WHETHER A PERMISSION IS CHECKED. A permission is a property of a
 * USER; the auto-close timer (FR-07) and the reopen consumer (FR-08) have no user to
 * check, and inventing a service principal to check against would be a fiction. So
 * `Staff` is checked against Doc 03, and the other three are recorded but not checked —
 * their legitimacy comes from being server-internal paths that no request can reach.
 * The status machine still governs them: a timer cannot make an illegal transition
 * either.
 */
enum TransitionActor: string
{
    case Staff = 'staff';
    case Ai = 'ai';
    case Automation = 'automation';
    case System = 'system';

    public function requiresPermissionCheck(): bool
    {
        return $this === self::Staff;
    }

    /** The audit trail's actor vocabulary (Doc 17) — ai/automation are system-class there. */
    public function auditActor(): AuditActor
    {
        return $this === self::Staff ? AuditActor::Staff : AuditActor::System;
    }
}
