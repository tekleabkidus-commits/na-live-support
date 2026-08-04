<?php

declare(strict_types=1);

namespace App\Support\Rbac\Scopes;

use App\Models\User;

/**
 * The pre-Doc 11 production binding: nobody belongs to anything, so Team and
 * Department scopes are empty sets. Deliberately fail-closed — a role scoped
 * to Team sees NOTHING rather than everything until Doc 11 supplies real
 * membership (Doc 03 T-03, NFR-04, EC-04).
 */
final class EmptyMembership implements MembershipSource
{
    public function teamIdsFor(User $user): array
    {
        return [];
    }

    public function departmentIdsFor(User $user): array
    {
        return [];
    }
}
