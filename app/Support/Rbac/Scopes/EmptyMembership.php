<?php

declare(strict_types=1);

namespace App\Support\Rbac\Scopes;

use App\Models\User;

/**
 * The pre-Doc 11 production binding (S19 → S11 step 3), now the TEST-ONLY fail-closed
 * control: nobody belongs to anything, so Team and Department scopes are empty sets.
 * Doc 11 (S11 step 3, Packet 3 countersigned) bound OrgMembership in production;
 * MembershipTransitionTest asserts this class is NOT the bound source and never
 * silently returns (Doc 03 T-03, NFR-04, EC-04).
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

    public function teamReachDepartmentIdsFor(User $user): array
    {
        return [];
    }
}
