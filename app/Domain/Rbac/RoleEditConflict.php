<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Role;
use RuntimeException;

/**
 * EC-03: two admins edited the same role; this carries the WINNER's current
 * state so the loser's screen can show the diff and offer re-apply.
 */
final class RoleEditConflict extends RuntimeException
{
    public function __construct(public readonly Role $winner)
    {
        parent::__construct('The role changed while you were editing it (EC-03).');
    }
}
