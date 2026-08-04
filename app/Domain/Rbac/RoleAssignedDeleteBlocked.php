<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Role;
use RuntimeException;

/**
 * FR-08c/EC-01: deletion blocked while assigned — carries the count for the
 * §8 error state's "count + reassign shortcut". EC-01's "role deleted
 * mid-session" is structurally impossible because this throws first.
 */
final class RoleAssignedDeleteBlocked extends RuntimeException
{
    public function __construct(public readonly Role $role, public readonly int $assignedCount)
    {
        parent::__construct(sprintf('Role "%s" is assigned to %d user(s) and cannot be deleted (FR-08c).', $role->name, $assignedCount));
    }
}
