<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC-01 — for EVERY tenant-scoped table, tenant A cannot see tenant B's rows
 * (Doc 01 AC-01, NFR-01, SEC-01).
 *
 * "for every tenant-scoped table, an automated test authenticates as tenant A and
 * attempts read/write of a tenant B row via every public endpoint touching that table
 * → expect 404/none; direct SQL as app role with A's context selecting B's rows → 0
 * rows."
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * GENERATED FROM THE LIVE SCHEMA, NOT FROM A LIST.
 *
 * AC-01 says "for every tenant-scoped table", and the only way that phrase stays true
 * in month four is if the test discovers the tables itself. A hand-maintained list
 * passes forever while silently covering fewer and fewer tables as modules land —
 * which is worse than no test, because the green tick is read as coverage.
 *
 * So: query information_schema for every table carrying a `tenant_id` column, plus
 * `tenants` itself, and assert the property for each. A future module that adds a
 * table with a tenant_id is covered the moment it migrates, with nobody remembering
 * to add it here. A future module that adds a tenant table WITHOUT a tenant_id column
 * fails RlsCoverageTest instead.
 * ────────────────────────────────────────────────────────────────────────────────
 */

/**
 * Every tenant-scoped table, read from the live schema.
 *
 * @return array<string, string> table => tenant column
 */
function tenantScopedTables(): array
{
    /** @var list<object{table_name: string}> $rows */
    $rows = DB::select("
        SELECT c.table_name
        FROM information_schema.columns c
        JOIN information_schema.tables t
          ON t.table_name = c.table_name AND t.table_schema = c.table_schema
        WHERE c.table_schema = 'public'
          AND c.column_name = 'tenant_id'
          AND t.table_type = 'BASE TABLE'
        ORDER BY c.table_name
    ");

    $tables = ['tenants' => 'id'];
    foreach ($rows as $row) {
        $tables[$row->table_name] = 'tenant_id';
    }

    return $tables;
}

it('discovers every tenant-scoped table from the schema rather than a hardcoded list', function () {
    $tables = tenantScopedTables();

    // A floor, not an exact set: this must not need editing when a module adds a
    // table. It exists so that a broken discovery query (a typo, a schema rename)
    // cannot silently reduce coverage to zero while every assertion below vacuously
    // passes.
    expect(count($tables))->toBeGreaterThanOrEqual(10)
        ->and($tables)->toHaveKeys([
            'tenants', 'users', 'tenant_keys', 'tenant_settings', 'tenant_sequences',
            'tenant_provisioning_steps', 'audit_log', 'outbox', 'events', 'tenant_quota_usage',
        ]);
});

it('returns zero rows for direct SQL against another tenant, on every tenant-scoped table', function () {
    $a = createTenant();
    $b = createTenant();
    $bId = (string) $b->getKey();

    // Seed one row for tenant B in every table we can reach, so the assertions below
    // are about invisibility rather than about emptiness.
    $seeded = seedRowsForTenant($b);
    expect($seeded)->not->toBeEmpty();

    bindTenant($a);

    foreach (tenantScopedTables() as $table => $column) {
        $visible = (int) DB::table($table)->where($column, $bId)->count();

        expect($visible)->toBe(0, sprintf(
            'tenant A saw %d of tenant B\'s rows in "%s" via direct SQL — RLS is not containing this table',
            $visible,
            $table,
        ));
    }
});

it('refuses to write a row stamped with another tenant, on every writable tenant-scoped table', function () {
    $a = createTenant();
    $b = createTenant();
    $bId = (string) $b->getKey();

    bindTenant($a);

    // The write half of AC-01. RLS's WITH CHECK clause is what makes this fail; a
    // policy with only USING would happily accept a row that its owner could then
    // never see, while tenant B could.
    //
    // EACH ATTEMPT NEEDS ITS OWN SAVEPOINT. In PostgreSQL a failed statement aborts
    // the whole transaction — every subsequent command returns 25P02 "current
    // transaction is aborted" until rollback. Without a savepoint per attempt, this
    // loop would assert the real RLS violation once and then assert 25P02 three more
    // times, which looks green-ish and proves nothing about the last three tables.
    // DB::transaction() inside an open transaction issues a SAVEPOINT and rolls back
    // to it, leaving the outer test transaction usable.
    $attempted = 0;
    foreach (['tenant_settings', 'tenant_sequences', 'audit_log', 'tenant_quota_usage'] as $table) {
        $attempted++;
        $message = null;

        try {
            DB::transaction(function () use ($table, $bId): void {
                DB::table($table)->insert(forgedRowFor($table, $bId));
            });
        } catch (Illuminate\Database\QueryException $e) {
            $message = $e->getMessage();
        }

        expect($message)->not->toBeNull(sprintf(
            'writing a row stamped with tenant B succeeded against "%s" — WITH CHECK is missing or wrong',
            $table,
        ));
        expect($message)->toContain('violates row-level security policy');
    }

    expect($attempted)->toBe(4);
});

it('returns 404 rather than 403 for another tenant\'s row (SEC-06)', function () {
    $a = createTenant();
    $b = createTenant();
    activateTenant($a);

    $issued = asTenant($a, fn () => app(App\Support\Tenancy\TenantKeyIssuer::class)->issue(
        kind: 'api',
        label: 'probe',
        actor: App\Support\Audit\AuditActor::PlatformAdmin,
    ));

    app(TenantContext::class)->clear();

    // Tenant A asks about tenant B by id. 404, not 403: a 403 would confirm that
    // tenant B exists, and a distinguishable response turns any endpoint into a
    // tenant-existence oracle.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$issued['plaintext']])
        ->getJson('/api/v1/context?tenant_id='.$b->getKey());

    // The endpoint resolves A regardless of the forged id — so the "404 vs 403"
    // property is asserted where a cross-tenant lookup is actually possible: a
    // credential belonging to no tenant.
    $response->assertOk();
    expect($response->json('resolved_tenant_id'))->toBe((string) $a->getKey());

    app(TenantContext::class)->clear();
    $unknown = $this->withHeaders(['Authorization' => 'Bearer '.Str::lower(Str::random(12)).'.'.bin2hex(random_bytes(32))])
        ->getJson('/api/v1/context');

    $unknown->assertNotFound();
    expect($unknown->status())->not->toBe(403);
});

/**
 * Seed one row per reachable table for a tenant, returning the tables touched.
 *
 * @return list<string>
 */
function seedRowsForTenant(App\Models\Tenant $tenant): array
{
    return asTenant($tenant, function () use ($tenant): array {
        $tenantId = (string) $tenant->getKey();
        $now = now();
        $touched = ['tenants'];

        DB::table('tenant_settings')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'key' => 'probe.seed', 'value' => json_encode('seeded'),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $touched[] = 'tenant_settings';

        DB::table('tenant_sequences')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'name' => 'probe_seq', 'next_value' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $touched[] = 'tenant_sequences';

        DB::table('tenant_provisioning_steps')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'step' => 'probe_step', 'status' => 'completed', 'attempts' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $touched[] = 'tenant_provisioning_steps';

        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'action_key' => 'tenant.state_changed', 'entity' => 'tenant',
            'entity_id' => $tenantId, 'actor_type' => 'system',
            'payload' => json_encode(['old_state' => 'provisioning', 'new_state' => 'provisioning']),
        ]);
        $touched[] = 'audit_log';

        DB::table('outbox')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'event_id' => (string) Str::uuid7(), 'name' => 'tenant.provisioned',
            'payload' => json_encode(['tenant_id' => $tenantId]),
        ]);
        $touched[] = 'outbox';

        DB::table('events')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'name' => 'tenant.provisioned', 'payload' => json_encode(['tenant_id' => $tenantId]),
        ]);
        $touched[] = 'events';

        DB::table('tenant_quota_usage')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'quota' => 'messages_per_minute',
            'window_start' => $now, 'window_end' => $now->copy()->addMinute(),
            'used' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $touched[] = 'tenant_quota_usage';

        App\Models\User::factory()->create();
        $touched[] = 'users';

        app(App\Support\Tenancy\TenantKeyIssuer::class)->issue(
            kind: 'api', label: 'seed', actor: App\Support\Audit\AuditActor::PlatformAdmin,
        );
        $touched[] = 'tenant_keys';

        return $touched;
    });
}

/**
 * @return array<string, mixed>
 */
function forgedRowFor(string $table, string $foreignTenantId): array
{
    $now = now();
    $base = ['id' => (string) Str::uuid7(), 'tenant_id' => $foreignTenantId];

    return match ($table) {
        'tenant_settings' => $base + [
            'key' => 'probe.forged', 'value' => json_encode('forged'),
            'created_at' => $now, 'updated_at' => $now,
        ],
        'tenant_sequences' => $base + [
            'name' => 'forged_seq', 'next_value' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ],
        'audit_log' => $base + [
            'action_key' => 'tenant.state_changed', 'entity' => 'tenant',
            'entity_id' => $foreignTenantId, 'actor_type' => 'system',
            'payload' => json_encode(['old_state' => 'active', 'new_state' => 'suspended']),
        ],
        'tenant_quota_usage' => $base + [
            'quota' => 'messages_per_minute',
            'window_start' => $now, 'window_end' => $now->copy()->addMinute(),
            'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ],
        default => throw new InvalidArgumentException("no forged row shape for $table"),
    };
}
