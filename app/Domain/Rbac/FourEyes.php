<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\PendingGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityCacheInvalidator;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FR-08b / T-06 / EC-08 / FS-04 — the four-eyes machinery.
 *
 * When `rbac.four_eyes` is ON, a HIGH-RISK grant writes a pending_grants row
 * instead of taking effect; the resolver ignores that table entirely, so a
 * pending grant confers NOTHING (FS-04's fail-safe is structural: any
 * failure between request and approval leaves rows pending, visible, inert).
 * Approval by a DISTINCT second admin (the decided_by <> requested_by CHECK
 * backs the code path) applies the grant and audits; rows auto-expire 24 h
 * after creation, by DB time.
 *
 * APPROVAL RIGHTS ARE RULING R-9's: holders of admin.roles.manage approve
 * role grants; holders of admin.overrides.manage approve override grants.
 * No admin.roles.approve key exists — the catalog is closed at 111 (the
 * drawn chip in B6-S10 is a filed class-3 design defect).
 *
 * EC-08: the setting REFUSES to enable without ≥2 users holding approval
 * rights — otherwise every request would wait for a person forbidden to
 * approve their own.
 */
final class FourEyes
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CapabilityMapProvider $maps,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly CapabilityCacheInvalidator $invalidator,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('rbac.four_eyes');
    }

    /**
     * EC-08's deadlock guard. Throws when enabling would deadlock.
     */
    public function assertCanEnable(): void
    {
        $holders = 0;
        foreach (User::query()->whereNotNull('role_id')->get() as $user) {
            $map = $this->maps->mapFor($user);
            if ($map->allows('admin.roles.manage') || $map->allows('admin.overrides.manage')) {
                $holders++;
                if ($holders >= 2) {
                    return;
                }
            }
        }

        throw new RuntimeException(
            'rbac.four_eyes cannot be enabled with fewer than two approval-rights holders (EC-08): '
            .'every high-risk request would wait for a person who is not allowed to approve her own.',
        );
    }

    public function requestRoleGrant(Role $role, string $permission, User $requester, string $reason): PendingGrant
    {
        return $this->request('role', $role->identifier(), null, $permission, $requester, $reason);
    }

    public function requestOverrideGrant(User $subject, string $permission, User $requester, string $reason): PendingGrant
    {
        return $this->request('override', null, $subject->identifier(), $permission, $requester, $reason);
    }

    /**
     * @throws RuntimeException on self-approval, wrong rights, or expiry
     */
    public function approve(PendingGrant $pending, User $approver): void
    {
        $this->assertDecidable($pending, $approver);

        DB::transaction(function () use ($pending, $approver): void {
            // Guarded claim under the row; the DB CHECK refuses self-approval
            // even if this code path is bypassed.
            $claimed = PendingGrant::query()
                ->whereKey($pending->getKey())
                ->where('status', 'pending')
                ->whereRaw('expires_at > now()')
                ->update([
                    'status' => 'approved',
                    'decided_by' => $approver->identifier(),
                    'decided_at' => DB::raw('now()'),
                ]);
            if ($claimed !== 1) {
                throw new RuntimeException('This request is no longer pending.');
            }

            $this->apply($pending);

            $this->events->publish('rbac.highrisk.confirmed', [
                'tenant_id' => $pending->tenantId(),
                'pending_grant_id' => $pending->identifier(),
                'permission' => $pending->permission,
                'actor_id' => $approver->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.highrisk.confirmed',
                entity: 'permission_grant',
                entityId: $pending->identifier(),
                actorType: AuditActor::Staff,
                actorId: $approver->identifier(),
                payload: ['permission' => $pending->permission, 'requested_by' => $pending->requested_by],
            );
        });

        $this->invalidateSubject($pending);
    }

    public function reject(PendingGrant $pending, User $approver): void
    {
        $this->assertDecidable($pending, $approver);

        DB::transaction(function () use ($pending, $approver): void {
            $claimed = PendingGrant::query()
                ->whereKey($pending->getKey())
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'decided_by' => $approver->identifier(),
                    'decided_at' => DB::raw('now()'),
                ]);
            if ($claimed !== 1) {
                throw new RuntimeException('This request is no longer pending.');
            }

            $this->events->publish('rbac.highrisk.rejected', [
                'tenant_id' => $pending->tenantId(),
                'pending_grant_id' => $pending->identifier(),
                'permission' => $pending->permission,
                'actor_id' => $approver->identifier(),
            ]);
            $this->audit->record(
                actionKey: 'rbac.highrisk.rejected',
                entity: 'permission_grant',
                entityId: $pending->identifier(),
                actorType: AuditActor::Staff,
                actorId: $approver->identifier(),
                payload: ['permission' => $pending->permission, 'requested_by' => $pending->requested_by],
            );
        });
    }

    private function request(string $subjectType, ?string $roleId, ?string $userId, string $permission, User $requester, string $reason): PendingGrant
    {
        return DB::transaction(function () use ($subjectType, $roleId, $userId, $permission, $requester, $reason): PendingGrant {
            $pending = PendingGrant::create([
                'subject_type' => $subjectType,
                'role_id' => $roleId,
                'user_id' => $userId,
                'permission' => $permission,
                'requested_by' => $requester->identifier(),
                'reason' => $reason,
                'expires_at' => DB::scalar("SELECT now() + interval '24 hours'"),
            ]);

            $this->events->publish('rbac.highrisk.granted', [
                'tenant_id' => $pending->tenantId(),
                'permission' => $permission,
                'subject_type' => $subjectType,
                'subject_id' => $roleId ?? $userId,
                'actor_id' => $requester->identifier(),
                'pending' => true,
            ]);
            $this->audit->record(
                actionKey: 'rbac.highrisk.granted',
                entity: 'permission_grant',
                entityId: $pending->identifier(),
                actorType: AuditActor::Staff,
                actorId: $requester->identifier(),
                payload: [
                    'permission' => $permission,
                    'subject_type' => $subjectType,
                    'subject_id' => $roleId ?? $userId,
                    'pending' => true,
                ],
            );

            return $pending;
        });
    }

    private function assertDecidable(PendingGrant $pending, User $approver): void
    {
        if ($approver->identifier() === $pending->requested_by) {
            throw new RuntimeException('The requester cannot decide their own high-risk grant (FR-08b).');
        }

        $requiredRight = $pending->subject_type === 'role' ? 'admin.roles.manage' : 'admin.overrides.manage';
        if (! $this->maps->mapFor($approver)->allows($requiredRight)) {
            throw new RuntimeException(sprintf('Deciding this request requires %s (ruling R-9).', $requiredRight));
        }
    }

    private function apply(PendingGrant $pending): void
    {
        if ($pending->subject_type === 'role') {
            $role = Role::query()->whereKey($pending->role_id)->firstOrFail();
            $permissions = $role->permissions;
            if (! in_array($pending->permission, $permissions, true)) {
                $permissions[] = $pending->permission;
                $role->forceFill([
                    'permissions' => $permissions,
                    'version' => $role->version + 1,
                ])->save();
            }

            return;
        }

        $subject = User::query()->whereKey($pending->user_id)->firstOrFail();
        // Replace any live override for the key, then apply the approved one.
        $subject->getConnection()->table('user_permission_overrides')
            ->where('user_id', $subject->identifier())
            ->where('permission', $pending->permission)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => DB::raw('now()'), 'revoke_cause' => 'removed', 'revoked_by' => $pending->requested_by]);
        \App\Models\UserPermissionOverride::create([
            'user_id' => $subject->identifier(),
            'permission' => $pending->permission,
            'effect' => 'allow',
            'granted_by' => $pending->requested_by,
            'reason' => $pending->reason,
        ]);
    }

    private function invalidateSubject(PendingGrant $pending): void
    {
        if ($pending->subject_type === 'role') {
            $role = Role::query()->whereKey($pending->role_id)->first();
            if ($role !== null) {
                $this->invalidator->forgetRoleMembers($role);
            }

            return;
        }

        if ($pending->user_id !== null) {
            $this->invalidator->forgetUserId($pending->user_id);
        }
    }
}
