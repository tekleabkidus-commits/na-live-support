<?php

declare(strict_types=1);

namespace App\Domain\Auth\Tokens;

use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Find-or-create the service account a machine token binds to (Doc 02 FR-19,
 * BR-03; step-9 ruling Q-B).
 *
 * A service account IS a user row — that is what makes BR-03 free: the token's
 * tokenable edge points at a visible, named principal, so audit attribution
 * works exactly as it does for humans, and "the system" never appears.
 *
 * NON-LOGIN, LAYER ONE: the password is the argon2id hash of 32 random bytes
 * DISCARDED here — no credential exists that could ever verify. Layer two is
 * resolve_staff_login() excluding service accounts entirely (see the
 * service-account migration). `password_set_at` stays NULL — the account never
 * completed FR-17's invited flow because it has no such flow.
 *
 * THE EMAIL IS SYNTHETIC AND SELF-IDENTIFYING: `<slug>@service-accounts.invalid`
 * — RFC 2606 reserves .invalid, so it can never receive mail or collide with a
 * real address, the same reasoning as the fixture phone-number convention.
 * Uniqueness is per (tenant, email), so the NAME is the tenant-scoped identity
 * of the account: naming an existing one reuses it (that is what lets two
 * integrations share one account deliberately), naming a new one creates it,
 * audited as auth.service_account.created.
 *
 * ITS ROLE IS A REAL ROLE (BR-07, closed at S19 step 7): service accounts
 * follow identical rules — no special system role exists, assignRole() below
 * writes users.role_id like anyone's, audited as rbac.user.role_changed —
 * and the T-05 intersection then runs against genuinely resolved
 * permissions. A NEW service account without a role would mint dead tokens
 * (implicit deny holds everything at zero), so the creation surface requires
 * one; reuse keeps the existing role unless the caller changes it.
 */
final class ServiceAccountProvisioner
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly \App\Support\Events\EventPublisher $events,
        private readonly \App\Support\Rbac\CapabilityCacheInvalidator $invalidator,
    ) {}

    /**
     * BR-07: assign (or change) the service account's role — the same column,
     * the same audit trail, the same event as any user's role change.
     */
    public function assignRole(User $account, \App\Models\Role $role, ?string $actorId): void
    {
        $oldRoleId = $account->getAttribute('role_id');
        if ($oldRoleId === $role->identifier()) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($account, $role, $oldRoleId, $actorId): void {
            $account->forceFill(['role_id' => $role->identifier()])->save();

            $this->events->publish('rbac.user.role_changed', [
                'tenant_id' => $account->tenantId(),
                'user_id' => $account->identifier(),
                'old_role_id' => is_string($oldRoleId) ? $oldRoleId : null,
                'new_role_id' => $role->identifier(),
                'actor_id' => $actorId,
            ]);
            $this->audit->record(
                actionKey: 'rbac.user.role_changed',
                entity: 'user',
                entityId: $account->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actorId,
                payload: [
                    'old_role_id' => is_string($oldRoleId) ? $oldRoleId : null,
                    'new_role_id' => $role->identifier(),
                ],
            );
        });

        $this->invalidator->forgetUser($account);
    }

    public function findOrCreate(string $name, CarbonImmutable $now, ?string $actorId = null): User
    {
        $email = sprintf('%s@service-accounts.invalid', Str::slug($name) === '' ? 'service-account' : Str::slug($name));

        $existing = User::query()
            ->where('email', $email)
            ->whereNotNull('service_account_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = new User;
        $account->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(bin2hex(random_bytes(32))),
            'service_account_at' => $now,
        ]);
        $account->save();

        $this->audit->record(
            actionKey: 'auth.service_account.created',
            entity: 'user',
            entityId: $account->identifier(),
            actorType: AuditActor::Staff,
            actorId: $actorId,
            payload: ['name' => $name],
        );

        return $account;
    }
}
