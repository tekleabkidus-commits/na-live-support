<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;

/**
 * THE T-05 SEAM: "owner.effective_permissions", as an interface (Doc 02 T-05;
 * Doc 03 T-02).
 *
 * Doc 02's token step needs the intersection `token.scopes ∩
 * owner.effective_permissions` before Doc 03's resolver exists. This interface
 * is the line between the two documents: the intersection engine
 * (TokenScopeGate) consumes it and is FINISHED — Doc 03's session replaces the
 * bound implementation with its real capability-map resolver and touches
 * nothing else. If you are building Doc 03: bind your resolver to this
 * interface; do not modify TokenScopeGate or RequirePermission.
 */
interface EffectivePermissions
{
    /**
     * Every permission key this user effectively holds, right now.
     *
     * @return list<string>
     */
    public function for(User $user): array;
}
