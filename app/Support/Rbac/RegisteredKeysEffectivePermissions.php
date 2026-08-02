<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;

/**
 * The Doc-02-era stand-in behind the EffectivePermissions seam.
 *
 * MIRRORS RequirePermission's documented staff posture exactly: until Doc 03's
 * resolver lands there are no roles to check a grant against, so an active
 * staff principal "holds" every registered key and a disabled one holds none.
 * That makes the T-05 intersection REAL arithmetic today — a token scoped to
 * [tickets.view] intersected with "everything registered" is still just
 * [tickets.view] — while the live-permission-loss half of AC-05/EC-06 is
 * proven against a test double of the interface and stays PARTIAL by owner
 * ruling Q5 until this class is replaced.
 *
 * Doc 03: delete this class when your resolver binds to the seam.
 */
final class RegisteredKeysEffectivePermissions implements EffectivePermissions
{
    public function __construct(private readonly PermissionRegistry $registry) {}

    public function for(User $user): array
    {
        if ($user->isDisabled()) {
            return [];
        }

        return array_keys($this->registry->keys());
    }
}
