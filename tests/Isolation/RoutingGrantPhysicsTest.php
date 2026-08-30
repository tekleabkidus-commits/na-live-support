<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 5's grant physics, asserted from the catalog — the
 * KbGrantPhysicsTest shape for Doc 11's eleven tables + the Doc 06 tickets delta
 * (S11 step 2b). The denied sets are the AMENDMENT'S OWN FILED SPLITS per privilege,
 * asserted EXACTLY (a widened grant is as red as a narrowed one). Also here:
 * routing_decisions holds NO UPDATE grant at any level and no DELETE (FR-17/BR-06 —
 * the audit surface is physics; the refusal EXECUTES); FLAG C — queue_members and
 * user_departments hold no DELETE (the 42501 control executes on both); every table
 * is FORCED RLS with tenant_isolation; the is_rg partial unique is the only
 * per-tenant unique on queues (FR-03); the temporary single-source CHECK refuses a
 * temporary row without its Doc 03 override (SEC-03) on BOTH membership tables; the
 * two FLAG-F/FLAG-G trigger functions exist and are NOT SECURITY DEFINER.
 */
const DOC11_TABLES = [
    'departments', 'teams', 'team_members', 'queues', 'queue_teams', 'queue_members',
    'user_departments', 'user_skills', 'user_routing_capacity',
    'routing_decisions', 'agent_assignment_stats',
];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC11_DENIED_COLUMNS = [
    'departments' => [
        'INSERT' => ['created_at', 'deleted_at'],
        'UPDATE' => ['created_at', 'id', 'seeded', 'tenant_id'],
    ],
    'teams' => [
        'INSERT' => ['created_at', 'deleted_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'team_members' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['created_at', 'id', 'team_id', 'tenant_id', 'user_id'],
    ],
    'queues' => [
        'INSERT' => ['created_at', 'deleted_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'queue_teams' => [
        'INSERT' => ['created_at'],
        'UPDATE' => '*',
    ],
    'queue_members' => [
        'INSERT' => ['created_at', 'ended_at'],
        'UPDATE' => ['created_at', 'expires_at', 'granted_by', 'id', 'kind', 'override_id', 'queue_id', 'tenant_id', 'user_id'],
    ],
    'user_departments' => [
        'INSERT' => ['created_at', 'ended_at'],
        'UPDATE' => ['created_at', 'department_id', 'expires_at', 'granted_by', 'id', 'kind', 'override_id', 'tenant_id', 'user_id'],
    ],
    'user_skills' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id', 'user_id'],
    ],
    'user_routing_capacity' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id', 'user_id'],
    ],
    'routing_decisions' => [
        'INSERT' => ['at', 'created_at'],
        'UPDATE' => '*',
    ],
    'agent_assignment_stats' => [
        'INSERT' => ['created_at', 'last_assigned_at'],
        'UPDATE' => ['created_at', 'id', 'last_assigned_at', 'tenant_id', 'user_id'],
    ],
];

/** @return list<string> */
function routingColumns(string $table): array
{
    /** @var list<object{column_name: string}> $rows */
    $rows = DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY column_name', ['public', $table]);

    return array_map(static fn (object $row): string => $row->column_name, $rows);
}

it('R-19b/ADR-0003: the app role denied set per table and privilege is EXACTLY the amendment-5 filing', function (): void {
    foreach (DOC11_TABLES as $table) {
        $columns = routingColumns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $denied = [];
            foreach ($columns as $column) {
                /** @var object{ok: bool} $row */
                $row = DB::selectOne('SELECT has_column_privilege(\'app\', ?, ?, ?) AS ok', [$table, $column, $privilege]);
                if (! $row->ok) {
                    $denied[] = $column;
                }
            }
            sort($denied);
            $expected = DOC11_DENIED_COLUMNS[$table][$privilege];
            expect($denied)->toBe($expected === '*' ? $columns : $expected, sprintf('%s %s denied set drifted from SCHEMA AMENDMENT 5 (found: %s)', $table, $privilege, implode(', ', $denied)));
        }
    }
});

it('FR-17/BR-06 physics: routing_decisions carries NO app UPDATE grant at any level and no DELETE — the refusal EXECUTES', function (): void {
    /** @var object{ok: bool} $row */
    $row = DB::selectOne("SELECT has_table_privilege('app', 'routing_decisions', 'UPDATE') AS ok");
    expect($row->ok)->toBeFalse('routing_decisions is updatable by app — the record is no longer an audit surface');
    /** @var object{n: int|string} $cols */
    $cols = DB::selectOne("SELECT count(*) AS n FROM information_schema.column_privileges WHERE grantee = 'app' AND table_name = 'routing_decisions' AND privilege_type = 'UPDATE'");
    expect((int) $cols->n)->toBe(0, 'routing_decisions has column-level UPDATE grants for app');

    $tenant = createTenant();
    bindTenant($tenant);
    foreach (['UPDATE routing_decisions SET outcome = \'manual\'', 'DELETE FROM routing_decisions'] as $sql) {
        try {
            DB::transaction(static fn () => DB::statement($sql));
            $this->fail("app executed: {$sql}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $sql);
        }
    }
});

it('ADR 0003 / FLAG C: app holds no DELETE on departments, teams, queues, queue_members, user_departments, user_skills, routing_decisions, agent_assignment_stats — and the 42501 control executes on both membership tables', function (): void {
    foreach (['departments', 'teams', 'queues', 'queue_members', 'user_departments', 'user_skills', 'routing_decisions', 'agent_assignment_stats'] as $table) {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($row->ok)->toBeFalse("app can DELETE from {$table}");
    }
    // The three the amendment GRANTS deletion on — asserted so a narrowing is as visible as a widening.
    foreach (['team_members', 'queue_teams', 'user_routing_capacity'] as $table) {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($row->ok)->toBeTrue("app lost DELETE on {$table} — the amendment grants it");
    }
    $tenant = createTenant();
    bindTenant($tenant);
    foreach (['queue_members', 'user_departments'] as $table) {
        try {
            DB::transaction(static fn () => DB::statement("DELETE FROM {$table}"));
            $this->fail("app deleted from {$table}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $table);
        }
    }
});

it('N-01: every Doc 11 table is FORCED row-level security with the tenant_isolation policy', function (): void {
    foreach (DOC11_TABLES as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");
        /** @var object{n: int|string} $policy */
        $policy = DB::selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);
        expect((int) $policy->n)->toBe(1, "{$table} lacks the tenant_isolation policy");
    }
});

it('FR-03 physics: is_rg is at most one per tenant (partial unique), and the temporary single-source CHECK refuses a temporary membership without its Doc 03 override on BOTH tables', function (): void {
    /** @var object{indexdef: string}|null $idx */
    $idx = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'queues' AND indexname = 'queues_one_rg_per_tenant'");
    expect($idx)->not->toBeNull('the is_rg partial unique index is gone')
        ->and($idx->indexdef)->toContain('UNIQUE')
        ->and($idx->indexdef)->toContain('WHERE');

    foreach (['queue_members', 'user_departments'] as $table) {
        /** @var object{n: int|string} $chk */
        $chk = DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname = ? AND contype = 'c'", ["{$table}_temporary_single_source"]);
        expect((int) $chk->n)->toBe(1, "{$table} lost its temporary single-source CHECK");
    }

    // Executed, not inferred: a temporary row with no override is refused by the database.
    $tenant = createTenant();
    bindTenant($tenant);
    $dept = (string) Str::uuid7();
    $queue = (string) Str::uuid7();
    DB::table('departments')->insert(['id' => $dept, 'tenant_id' => $tenant->identifier(), 'name' => json_encode(['en' => 'Physics']), 'slug' => 'physics']);
    DB::table('queues')->insert(['id' => $queue, 'tenant_id' => $tenant->identifier(), 'department_id' => $dept, 'name' => 'Physics']);
    $user = DB::table('users')->where('tenant_id', $tenant->identifier())->value('id');
    if (! is_string($user)) {
        $user = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $user, 'tenant_id' => $tenant->identifier(), 'name' => 'physics', 'email' => 'physics-'.$user.'@fixture.example', 'password' => 'x']);
    }
    try {
        DB::transaction(static fn () => DB::table('queue_members')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(), 'queue_id' => $queue, 'user_id' => $user,
            'kind' => 'temporary', 'expires_at' => DB::raw("clock_timestamp() + interval '1 hour'"), // no override_id
        ]));
        $this->fail('a temporary queue membership without a Doc 03 override was accepted');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('queue_members_temporary_single_source');
    }
    try {
        DB::transaction(static fn () => DB::table('user_departments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(), 'department_id' => $dept, 'user_id' => $user,
            'kind' => 'temporary', 'expires_at' => DB::raw("clock_timestamp() + interval '1 hour'"),
        ]));
        $this->fail('a temporary department access without a Doc 03 override was accepted');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('user_departments_temporary_single_source');
    }
});

it('FLAG F / FLAG G: the two stamping triggers exist as PLAIN plpgsql functions (never SECURITY DEFINER) on their tables, and app cannot name the stamps at either door', function (): void {
    foreach ([['agent_assignment_stats_stamp', 'agent_assignment_stats'], ['tickets_stamp_placement', 'tickets']] as [$fn, $table]) {
        /** @var object{prosecdef: bool}|null $row */
        $row = DB::selectOne('SELECT prosecdef FROM pg_proc WHERE proname = ?', [$fn]);
        expect($row)->not->toBeNull("{$fn}() does not exist")
            ->and($row->prosecdef)->toBeFalse("{$fn}() is SECURITY DEFINER — R-20's ceiling");
        /** @var object{n: int|string} $trg */
        $trg = DB::selectOne('SELECT count(*) AS n FROM pg_trigger WHERE tgname = ? AND NOT tgisinternal', ["{$fn}_trigger"]);
        expect((int) $trg->n)->toBe(1, "{$fn}_trigger is not attached to {$table}");
    }
    foreach ([['tickets', 'queued_at'], ['tickets', 'routed_at'], ['agent_assignment_stats', 'last_assigned_at']] as [$table, $column]) {
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            /** @var object{ok: bool} $row */
            $row = DB::selectOne('SELECT has_column_privilege(\'app\', ?, ?, ?) AS ok', [$table, $column, $privilege]);
            expect($row->ok)->toBeFalse("app may {$privilege} {$table}.{$column} — the stamp is no longer the database's");
        }
    }
    // And tickets.queue_id IS app-writable at both doors (the PlaceInQueue door's column).
    foreach (['INSERT', 'UPDATE'] as $privilege) {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne("SELECT has_column_privilege('app', 'tickets', 'queue_id', ?) AS ok", [$privilege]);
        expect($row->ok)->toBeTrue("app cannot {$privilege} tickets.queue_id");
    }
});
