<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityCacheInvalidator;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The mutating role operations behind the §8 screens (FR-02, FR-08a/c,
 * EC-03, EC-10, BR-03, BR-04) — every path events through the outbox
 * (FR-12/T-07), audits with old/new payloads, and invalidates the
 * capability caches of everyone affected (FR-07).
 *
 * Four-eyes composition (FR-08b): when enabled, HIGH-RISK keys in a create
 * or update are SPLIT OUT — the role is written without them and each
 * becomes a pending_grants row. The resolver never sees a pending grant, so
 * there is no window where an unapproved high-risk key enforces.
 */
final class RoleAdministration
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly CapabilityMapProvider $maps,
        private readonly SettingsRepository $settings,
        private readonly LastAdminGuard $lastAdmin,
        private readonly FourEyes $fourEyes,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly CapabilityCacheInvalidator $invalidator,
    ) {}

    /**
     * @param  array{name: string, description: ?string, color: string, permissions: list<string>, scopes: array<string, string>}  $attrs
     * @return array{role: Role, warnings: list<string>, pending: list<string>}
     */
    public function create(User $actor, array $attrs, string $reason = ''): array
    {
        $this->assertCatalogKeys($attrs['permissions']);
        $this->assertStrictGrant($actor, $attrs['permissions']);

        [$applied, $pendingKeys] = $this->splitHighRisk($actor, [], $attrs['permissions']);

        $role = DB::transaction(function () use ($actor, $attrs, $applied): Role {
            $role = new Role;
            $role->fill([
                'name' => $attrs['name'],
                'description' => $attrs['description'],
                'color' => $attrs['color'],
                'permissions' => $applied,
                'scopes' => $attrs['scopes'],
                'is_system' => false,
            ]);
            $role->save();

            $this->events->publish('rbac.role.created', [
                'tenant_id' => $role->tenantId(),
                'role_id' => $role->identifier(),
                'actor_id' => $actor->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.role.created',
                entity: 'role',
                entityId: $role->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actor->identifier(),
                payload: ['new' => $this->snapshot($role)],
            );

            return $role;
        });

        $this->queuePending($role, $pendingKeys, $actor, $reason);

        return ['role' => $role, 'warnings' => $this->warnings(), 'pending' => $pendingKeys];
    }

    /**
     * @param  array{name: string, description: ?string, color: string, permissions: list<string>, scopes: array<string, string>}  $attrs
     * @return array{role: Role, warnings: list<string>, pending: list<string>}
     *
     * @throws RoleEditConflict on an EC-03 version race
     */
    public function update(User $actor, Role $role, array $attrs, int $expectedVersion, string $reason = ''): array
    {
        $this->assertCatalogKeys($attrs['permissions']);
        $this->assertStrictGrant($actor, array_values(array_diff($attrs['permissions'], $role->permissions)));
        $this->lastAdmin->assertRoleEditKeepsAnAdmin($role, $attrs['permissions']);

        [$applied, $pendingKeys] = $this->splitHighRisk($actor, $role->permissions, $attrs['permissions']);

        $old = $this->snapshot($role);

        DB::transaction(function () use ($actor, $role, $attrs, $applied, $expectedVersion, $old): void {
            // EC-03: the optimistic lock. The loser's request carries a stale
            // version; the guarded UPDATE misses and the conflict carries the
            // winner's state for the re-apply flow.
            $claimed = Role::query()
                ->whereKey($role->getKey())
                ->where('version', $expectedVersion)
                ->update([
                    'name' => $attrs['name'],
                    'description' => $attrs['description'],
                    'color' => $attrs['color'],
                    'permissions' => json_encode($applied, JSON_THROW_ON_ERROR),
                    'scopes' => json_encode($attrs['scopes'], JSON_THROW_ON_ERROR),
                    'version' => $expectedVersion + 1,
                ]);
            if ($claimed !== 1) {
                throw new RoleEditConflict($role->fresh() ?? $role);
            }

            $role->refresh();

            $this->events->publish('rbac.role.updated', [
                'tenant_id' => $role->tenantId(),
                'role_id' => $role->identifier(),
                'actor_id' => $actor->identifier(),
                'version' => $role->version,
            ]);
            $this->audit->record(
                actionKey: 'rbac.role.updated',
                entity: 'role',
                entityId: $role->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actor->identifier(),
                payload: ['old' => $old, 'new' => $this->snapshot($role)],
            );
        });

        $this->invalidator->forgetRoleMembers($role);
        $this->queuePending($role, $pendingKeys, $actor, $reason);

        return ['role' => $role, 'warnings' => $this->warnings(), 'pending' => $pendingKeys];
    }

    /**
     * FR-08c: blocked while assigned — the block carries the count for the
     * §8 error state ("count + reassign shortcut"). Soft delete (R-11).
     *
     * @throws RoleAssignedDeleteBlocked
     */
    public function delete(User $actor, Role $role): void
    {
        $assigned = $role->users()->count();
        if ($assigned > 0) {
            throw new RoleAssignedDeleteBlocked($role, $assigned);
        }

        $old = $this->snapshot($role);

        DB::transaction(function () use ($actor, $role, $old): void {
            $role->delete();

            $this->events->publish('rbac.role.deleted', [
                'tenant_id' => $role->tenantId(),
                'role_id' => $role->identifier(),
                'actor_id' => $actor->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.role.deleted',
                entity: 'role',
                entityId: $role->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actor->identifier(),
                payload: ['old' => $old],
            );
        });
    }

    /**
     * BR-01: THE role change (one per user). FR-08a guards the demotion path.
     */
    public function assignRole(User $actor, User $subject, Role $role): void
    {
        $oldRoleId = $subject->getAttribute('role_id');
        if ($oldRoleId === $role->identifier()) {
            return;
        }

        // FR-08a: if the subject currently anchors the admin pair, the change
        // must not empty the pool — resolved against what they would LOSE.
        $this->lastAdmin->assertNotLastAdmin($subject);

        DB::transaction(function () use ($actor, $subject, $role, $oldRoleId): void {
            $subject->forceFill(['role_id' => $role->identifier()])->save();

            $this->events->publish('rbac.user.role_changed', [
                'tenant_id' => $subject->tenantId(),
                'user_id' => $subject->identifier(),
                'old_role_id' => is_string($oldRoleId) ? $oldRoleId : null,
                'new_role_id' => $role->identifier(),
                'actor_id' => $actor->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.user.role_changed',
                entity: 'user',
                entityId: $subject->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actor->identifier(),
                payload: [
                    'old_role_id' => is_string($oldRoleId) ? $oldRoleId : null,
                    'new_role_id' => $role->identifier(),
                ],
            );
        });

        $this->invalidator->forgetUser($subject);
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertCatalogKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (! $this->catalog->has($key)) {
                throw new RuntimeException(sprintf('Unknown permission key "%s" — the catalog is closed (BR-03).', $key));
            }
        }
    }

    /**
     * BR-04: with rbac.strict_grant on, an editor cannot grant what they do
     * not themselves hold.
     *
     * @param  list<string>  $granting
     */
    private function assertStrictGrant(User $actor, array $granting): void
    {
        if (! (bool) $this->settings->get('rbac.strict_grant')) {
            return;
        }

        $map = $this->maps->mapFor($actor);
        foreach ($granting as $key) {
            if (! $map->allows($key)) {
                throw new RuntimeException(sprintf(
                    'rbac.strict_grant is on: you cannot grant %s because you do not hold it yourself (BR-04).',
                    $key,
                ));
            }
        }
    }

    /**
     * FR-08b: with four-eyes ON, NEWLY ADDED high-risk keys go pending
     * instead of applying. Keys the role already held stay untouched.
     *
     * @param  list<string>  $current
     * @param  list<string>  $requested
     * @return array{0: list<string>, 1: list<string>} [applied, pending]
     */
    private function splitHighRisk(User $actor, array $current, array $requested): array
    {
        if (! $this->fourEyes->enabled()) {
            return [$requested, []];
        }

        $applied = [];
        $pending = [];
        foreach ($requested as $key) {
            $newlyAdded = ! in_array($key, $current, true);
            if ($newlyAdded && $this->catalog->isHigh($key)) {
                $pending[] = $key;
            } else {
                $applied[] = $key;
            }
        }

        return [$applied, $pending];
    }

    /**
     * @param  list<string>  $pendingKeys
     */
    private function queuePending(Role $role, array $pendingKeys, User $actor, string $reason): void
    {
        foreach ($pendingKeys as $key) {
            $this->fourEyes->requestRoleGrant($role, $key, $actor, $reason === '' ? 'Requested in the role editor.' : $reason);
        }
    }

    /** @return list<string> i18n keys for §8's warnings */
    private function warnings(): array
    {
        $warnings = [];

        // EC-10: soft-limit warning (platform dial, ruling R-11.3).
        $limitSetting = $this->settings->get('rbac.roles.soft_limit');
        $limit = is_numeric($limitSetting) ? (int) $limitSetting : 100;
        if (Role::query()->count() > $limit) {
            $warnings[] = 'rbac.warning.soft_limit';
        }

        return $warnings;
    }

    /**
     * @return array{name: string, description: ?string, color: string, permissions: list<string>, scopes: array<string, string>, is_system: bool, version: int}
     */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'color' => $role->color,
            'permissions' => $role->permissions,
            'scopes' => $role->scopes,
            'is_system' => $role->is_system,
            'version' => $role->version,
        ];
    }
}
