<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * FR-08a: the LAST user holding BOTH admin.roles.manage and
 * admin.users.manage cannot be demoted or disabled — a hard block whose
 * error NAMES who else holds the rights (§8's error state), because the
 * operator's way out is always "make someone else an admin first".
 *
 * "Holding" means EFFECTIVE permissions (role + overrides), resolved fresh —
 * a cached map must never decide an operation whose failure mode is
 * permanent lockout. Three paths are guarded and AC-05 proves each:
 * demotion (role change), disabling (UserDisabler's route), and a ROLE EDIT
 * that would strip the pair from its last holders.
 */
final class LastAdminGuard
{
    public const REQUIRED_PAIR = ['admin.roles.manage', 'admin.users.manage'];

    public function __construct(private readonly CapabilityMapProvider $maps) {}

    /**
     * Refuse if removing $user from the admin pool would empty it.
     *
     * @throws LastAdminProtected
     */
    public function assertNotLastAdmin(User $user): void
    {
        if (! $this->holdsPair($user)) {
            return;
        }

        $others = $this->otherHolders($user);
        if ($others === []) {
            throw new LastAdminProtected($user, []);
        }
    }

    /**
     * Refuse a ROLE EDIT that would strip the pair's last holders: if the
     * new permission set no longer covers the pair, every member relying on
     * this role for it must survive some other way — with one role per user
     * and overrides unable to ride along, that means another holder must
     * exist OUTSIDE this role.
     *
     * @param  list<string>  $newPermissions
     *
     * @throws LastAdminProtected
     */
    public function assertRoleEditKeepsAnAdmin(Role $role, array $newPermissions): void
    {
        if (array_diff(self::REQUIRED_PAIR, $newPermissions) === []) {
            return; // the pair survives the edit
        }

        /** @var list<User> $members */
        $members = $role->users()->get()->all();
        $memberIds = array_map(fn (User $member) => $member->identifier(), $members);

        $holdersElsewhere = array_filter(
            $this->allHolders(),
            fn (User $holder) => ! in_array($holder->identifier(), $memberIds, true),
        );

        if ($holdersElsewhere === [] && array_filter($members, fn (User $member) => $this->holdsPair($member)) !== []) {
            throw new LastAdminProtected(null, []);
        }
    }

    /** @return list<User> every user effectively holding the pair */
    public function allHolders(): array
    {
        $holders = [];
        // Bounded by candidates who could POSSIBLY hold the pair: members of
        // roles granting it, plus users carrying an allow override on either
        // key. Small by construction (BR-02: overrides are exceptions).
        // Disabled accounts hold nothing (Doc 02 FR-05/BR-04) — a pool of
        // disabled "admins" is an empty pool.
        foreach (User::query()->whereNotNull('role_id')->whereNull('disabled_at')->get() as $user) {
            if ($this->holdsPair($user)) {
                $holders[] = $user;
            }
        }

        return $holders;
    }

    /** @return list<array{id: string, name: string}> for the §8 error copy */
    public function otherHolders(User $except): array
    {
        return array_values(array_map(
            function (User $holder): array {
                $name = $holder->getAttribute('name');

                return ['id' => $holder->identifier(), 'name' => is_string($name) ? $name : ''];
            },
            array_filter($this->allHolders(), fn (User $holder) => $holder->identifier() !== $except->identifier()),
        ));
    }

    private function holdsPair(User $user): bool
    {
        $map = $this->maps->mapFor($user);

        foreach (self::REQUIRED_PAIR as $key) {
            if (! $map->allows($key)) {
                return false;
            }
        }

        return true;
    }
}
