<?php

declare(strict_types=1);

namespace App\Support\Rbac\Scopes;

use App\Models\User;

/**
 * THE DOC 11 SEAM (Doc 03 T-03): where Team and Department scope levels get
 * their membership sets from.
 *
 * Doc 11 owns the real structures (teams, departments, queue membership,
 * temporary queue pull-in grants — FR-09's second half) and binds the real
 * implementation when it lands. Until then:
 *
 *  - production binds EmptyMembership: every membership set is EMPTY, so
 *    Team/Dept scopes resolve to the empty set — fail closed (NFR-04/EC-04),
 *    never open;
 *  - the AC-03 fixture suite binds FixtureMembership and is the living
 *    contract Doc 11's implementation must re-prove against (the
 *    traceability row says exactly that — a bare DONE there is a false DONE).
 */
interface MembershipSource
{
    /** @return list<string> team ids the user belongs to */
    public function teamIdsFor(User $user): array;

    /** @return list<string> department ids the user belongs to */
    public function departmentIdsFor(User $user): array;
}
