<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;

/**
 * The REAL implementation behind the T-05 seam (Doc 02 T-05 ↔ Doc 03 T-02):
 * a user's effective keys are their capability map's allowed set — role +
 * overrides + expiries, cached ≤10 s with explicit invalidation.
 *
 * This replaced RegisteredKeysEffectivePermissions (the Doc 02-era
 * "everything registered" stand-in) at S19 step 7. TokenScopeGate consumes
 * this through the interface and was not touched: the machine-token
 * intersection now runs against genuinely resolved permissions, including
 * service accounts' real roles (BR-07 — no special system role exists).
 */
final class ResolvedEffectivePermissions implements EffectivePermissions
{
    public function __construct(private readonly CapabilityMapProvider $maps) {}

    public function for(User $user): array
    {
        if ($user->isDisabled()) {
            return [];
        }

        return $this->maps->mapFor($user)->allowedKeys();
    }
}
