<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Role;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Doc03/RoleSeeder — the consumer `tenant.provisioned` declared before this
 * session existed (EVENT-CATALOG): fills Doc 01 FR-06's `default_roles`
 * provisioning step with Appendix B's nine templates (FR-10, ruling R-10).
 *
 * IDEMPOTENT UNDER PROVISIONING CONCURRENCY (Doc 01's proofs re-run against
 * this): seeding twice yields ONE consistent set. The name's partial unique
 * index is the arbiter under a true race — the loser's insert dies in the
 * database and the catch treats it as "already seeded", which it is.
 *
 * EC-07 IS PART OF THE IDEMPOTENCY CONTRACT, not a separate feature:
 * `seeded_fingerprint` records the content hash the seeder wrote; a re-seed
 * (re-provisioning, template release) touches a template ONLY while its
 * current content still hashes to its fingerprint. An operator-edited
 * template is NEVER overwritten — a release's changes surface as changelog
 * suggestions in the roles UI (computed by diffing this class's current
 * definition against the row; nothing is stored for it). An operator-DELETED
 * template stays deleted: resurrecting it would overwrite an operator
 * decision with a platform one.
 */
final class RoleSeeder
{
    public function __construct(
        private readonly RoleTemplates $templates,
        private readonly PermissionCatalog $catalog,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Seed (or reconcile) the nine templates for the CURRENTLY BOUND tenant.
     *
     * @return int how many roles were created this call
     */
    public function seed(): int
    {
        $created = 0;

        foreach ($this->templates->all($this->catalog) as $name => $template) {
            $created += DB::transaction(function () use ($name, $template): int {
                $existing = Role::withTrashed()->where('name', $name)->first();

                if ($existing !== null) {
                    $this->reconcile($existing, $template);

                    return 0;
                }

                try {
                    $role = $this->create($name, $template);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // The concurrent provisioner won the race — the set the
                    // winner wrote IS the consistent set. Nothing to do.
                    return 0;
                }

                $this->events->publish('rbac.role.created', [
                    'tenant_id' => $role->tenantId(),
                    'role_id' => $role->identifier(),
                    'actor_id' => null,
                ]);
                $this->audit->record(
                    actionKey: 'rbac.role.created',
                    entity: 'role',
                    entityId: $role->identifier(),
                    actorType: AuditActor::System,
                    payload: ['new' => [
                        'name' => $name,
                        'permissions' => $template['permissions'],
                        'scopes' => $template['scopes'],
                        'is_system' => true,
                    ]],
                );

                return 1;
            });
        }

        return $created;
    }

    /**
     * @param  array{description: string, color: string, permissions: list<string>, scopes: array<string, string>}  $template
     */
    private function create(string $name, array $template): Role
    {
        $role = new Role;
        $role->fill([
            'name' => $name,
            'description' => $template['description'],
            'color' => $template['color'],
            'permissions' => $template['permissions'],
            'scopes' => $template['scopes'],
            'is_system' => true,
            'seeded_fingerprint' => self::fingerprint($name, $template),
        ]);
        $role->save();

        return $role;
    }

    /**
     * EC-07: update a template ONLY while it is still exactly what a seeder
     * wrote; an edited or deleted template belongs to the operator now.
     *
     * @param  array{description: string, color: string, permissions: list<string>, scopes: array<string, string>}  $template
     */
    private function reconcile(Role $existing, array $template): void
    {
        if ($existing->trashed() || ! $existing->is_system) {
            return;
        }

        $currentHash = self::contentHash(
            $existing->name,
            $existing->description ?? '',
            $existing->color,
            $existing->permissions,
            $existing->scopes,
        );
        if ($currentHash !== $existing->seeded_fingerprint) {
            return; // operator-edited — changelog suggestion territory, never an overwrite
        }

        $fresh = self::fingerprint($existing->name, $template);
        if ($fresh === $existing->seeded_fingerprint) {
            return; // identical release — nothing to write
        }

        $existing->fill([
            'description' => $template['description'],
            'color' => $template['color'],
            'permissions' => $template['permissions'],
            'scopes' => $template['scopes'],
            'seeded_fingerprint' => $fresh,
        ])->save();
    }

    /**
     * @param  array{description: string, color: string, permissions: list<string>, scopes: array<string, string>}  $template
     */
    public static function fingerprint(string $name, array $template): string
    {
        return self::contentHash($name, $template['description'], $template['color'], $template['permissions'], $template['scopes']);
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     */
    private static function contentHash(string $name, string $description, string $color, array $permissions, array $scopes): string
    {
        sort($permissions);
        ksort($scopes);

        return hash('sha256', json_encode([$name, $description, $color, $permissions, $scopes], JSON_THROW_ON_ERROR));
    }
}
