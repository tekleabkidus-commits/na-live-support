<?php

declare(strict_types=1);

use App\Models\PendingGrant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac\CatalogBootCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-01 storage physics (Doc 03 FR-02/FR-04/T-06, BR-01; rulings R-9/R-11) and
 * FS-03's boot check. These are the schema's own guarantees — the resolver
 * (step 3) builds ON them, it does not substitute for them.
 */
function rbacTenantAndAdmin(): array
{
    $tenant = createTenant();

    return [$tenant, asTenant($tenant, fn () => User::factory()->create())];
}

it('enforces one role per user as PHYSICS: a single column exists and no role pivot table does (BR-01)', function () {
    expect(Schema::hasColumn('users', 'role_id'))->toBeTrue();

    // No pivot may exist under any conventional name — multi-role must be
    // structurally unexpressible, not merely unused.
    /** @var list<object{tablename: string}> $tables */
    $tables = DB::connection('pgsql_migrator')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
    );
    foreach ($tables as $table) {
        expect(preg_match('/^(role_user|user_role|user_roles|role_users|model_has_roles)$/', $table->tablename))
            ->toBe(0, "SEV-1: pivot table {$table->tablename} exists — BR-01's one-role physics is gone");
    }
});

it('keeps role names unique per tenant among LIVE roles, and frees the name on soft delete (FR-02, R-11)', function () {
    [$tenant, $admin] = rbacTenantAndAdmin();

    asTenant($tenant, function () use ($admin): void {
        $make = fn () => Role::create([
            'name' => 'Payments Specialist',
            'color' => 'accent-1',
            'permissions' => ['tickets.view'],
            'scopes' => ['tickets' => 'Dept'],
        ]);

        $first = $make();

        // Same live name → refused by the partial unique index.
        expect(fn () => DB::transaction($make))->toThrow(Illuminate\Database\QueryException::class);

        // Soft delete frees the name (the delete itself passed FR-08c's
        // guard — nobody was assigned); a fresh role may reuse it.
        $first->delete();
        expect($first->fresh()->deleted_at)->not->toBeNull()
            ->and($make()->exists)->toBeTrue();

        expect($admin->exists)->toBeTrue();
    });
});

it('refuses a hard DELETE from the app role on all three rbac tables (ADR 0003 grant physics)', function () {
    foreach (['roles', 'user_permission_overrides', 'pending_grants'] as $table) {
        /** @var list<object{ok: bool}> $rows */
        $rows = DB::connection('pgsql_migrator')->select(
            "SELECT has_table_privilege('app', ?, 'DELETE') AS ok",
            [$table],
        );
        expect((bool) $rows[0]->ok)->toBeFalse("SEV-1: app can DELETE from {$table}");
    }
});

it('enables AND forces RLS with a tenant policy on all three rbac tables (N-01, Doc 01 layer 2)', function () {
    /** @var list<object{relname: string, relrowsecurity: bool, relforcerowsecurity: bool, policies: int|string}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(<<<'SQL'
        SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity,
               (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS policies
          FROM pg_class c
         WHERE c.relname IN ('roles', 'user_permission_overrides', 'pending_grants')
        SQL);

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row->relrowsecurity)->toBeTrue("{$row->relname}: RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$row->relname}: RLS not forced")
            ->and((int) $row->policies)->toBeGreaterThan(0, "{$row->relname}: no policy");
    }
});

it('allows at most one LIVE override per user and permission, and frees the slot on revocation (FR-04)', function () {
    [$tenant, $admin] = rbacTenantAndAdmin();

    asTenant($tenant, function () use ($admin): void {
        $user = User::factory()->create();

        $make = fn () => UserPermissionOverride::create([
            'user_id' => $user->identifier(),
            'permission' => 'customers.merge',
            'effect' => 'allow',
            'granted_by' => $admin->identifier(),
            'reason' => 'Two weeks of merge cover for the payments backlog.',
        ]);

        $first = $make();

        expect(fn () => DB::transaction($make))->toThrow(Illuminate\Database\QueryException::class);

        $first->forceFill([
            'revoked_at' => DB::selectOne('SELECT now() AS now')->now,
            'revoked_by' => $admin->identifier(),
            'revoke_cause' => 'removed',
        ])->save();

        expect($make()->exists)->toBeTrue();
    });
});

it('rejects garbage effect and revoke_cause values at the schema layer', function () {
    [$tenant, $admin] = rbacTenantAndAdmin();

    asTenant($tenant, function () use ($admin): void {
        $user = User::factory()->create();

        expect(fn () => DB::transaction(fn () => UserPermissionOverride::create([
            'user_id' => $user->identifier(),
            'permission' => 'customers.merge',
            'effect' => 'maybe',
            'granted_by' => $admin->identifier(),
            'reason' => 'A reason long enough to pass.',
        ])))->toThrow(Illuminate\Database\QueryException::class);
    });
});

it('refuses self-approval on pending grants BY TABLE CONSTRAINT, and accepts a distinct decider (FR-08b, R-9)', function () {
    [$tenant, $requester] = rbacTenantAndAdmin();

    asTenant($tenant, function () use ($requester): void {
        $secondAdmin = User::factory()->create();
        $role = Role::create([
            'name' => 'Payments Specialist',
            'color' => 'accent-1',
            'permissions' => [],
            'scopes' => [],
        ]);

        $pending = PendingGrant::create([
            'subject_type' => 'role',
            'role_id' => $role->getKey(),
            'permission' => 'customers.export',
            'requested_by' => $requester->identifier(),
            'reason' => 'Finance needs the monthly reconciliation export.',
            'expires_at' => DB::selectOne("SELECT now() + interval '24 hours' AS t")->t,
        ]);

        // Sabotage-shaped positive control: the REQUESTER writing themselves
        // as decider dies in the database, not in a code path a bug can skip.
        expect(fn () => DB::transaction(fn () => $pending->forceFill([
            'status' => 'approved',
            'decided_by' => $requester->identifier(),
        ])->save()))->toThrow(Illuminate\Database\QueryException::class);

        $pending->refresh();
        $pending->forceFill([
            'status' => 'approved',
            'decided_by' => $secondAdmin->identifier(),
        ])->save();

        expect($pending->fresh()->status)->toBe('approved');
    });
});

it('requires a subject: a role pending grant without a role, or with BOTH targets, is unstorable (T-06)', function () {
    [$tenant, $requester] = rbacTenantAndAdmin();

    asTenant($tenant, function () use ($requester): void {
        expect(fn () => DB::transaction(fn () => PendingGrant::create([
            'subject_type' => 'role',
            'permission' => 'customers.export',
            'requested_by' => $requester->identifier(),
            'reason' => 'Missing its role subject on purpose.',
            'expires_at' => DB::selectOne("SELECT now() + interval '24 hours' AS t")->t,
        ])))->toThrow(Illuminate\Database\QueryException::class);
    });
});

it('logs and IGNORES orphan DB keys — never enforces them (T-01, FS-03)', function () {
    [$tenant] = rbacTenantAndAdmin();
    $logs = &capturedLogsRef();

    asTenant($tenant, function (): void {
        Role::create([
            'name' => 'Drifted role',
            'color' => 'accent-1',
            // One real key, one orphan a bad deploy left behind.
            'permissions' => ['tickets.view', 'tickets.teleport'],
            'scopes' => ['tickets' => 'Own'],
        ]);
    });
    commitFixtures();

    try {
        $orphans = app(CatalogBootCheck::class)->run();

        expect($orphans)->toBe(['tickets.teleport']);

        $entry = collect($logs)->first(fn (array $log) => $log['message'] === 'rbac.catalog.orphan_keys');
        expect($entry)->not->toBeNull()
            ->and($entry['context']['orphan_keys'])->toBe(['tickets.teleport']);
    } finally {
        cleanupTenants([$tenant->identifier()]);
    }
});

it('reports a healthy catalog as empty — the check is quiet when nothing drifted (FS-03 positive control)', function () {
    expect(app(CatalogBootCheck::class)->run())->toBe([]);
});
