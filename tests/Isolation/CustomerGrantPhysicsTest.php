<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 grants ARE the invariants (SCHEMA AMENDMENT 2 + countersign ruling 2)
|--------------------------------------------------------------------------
|
| Same posture as Inbox/Ticket/TelegramGrantPhysicsTest: every grant-shaped
| claim in the countersigned amendment becomes a has_*_privilege() fact, the
| column split DERIVED FROM THE SCHEMA so a column arriving outside the filed
| analysis surfaces here BY NAME. THIS IS SABOTAGE #14's GUARD (migration-
| level, the S07 #11/#16 precedent).
|
| The filed analysis (ANSWERS, S08 SCHEMA AMENDMENT 2 + countersign):
| - customer_notes: created_at ORDERS the list (db-time-only); UPDATE reaches
|   exactly (pinned, updated_at) — pinning is FR-17's one named mutation.
| - customer_merge_snapshots: created_at ORDERS the EC-04 chain; UPDATE
|   reaches exactly (undone_at); INSERT cannot name undone_at (a snapshot is
|   born un-undone).
| - rg_flag_history: INSERT-ONLY (AC-05 immutability as physics) — UPDATE and
|   DELETE revoked outright; created_at db-time-only.
| - customer_identity_conflicts (ruling 2): resolution is a MARKER — UPDATE
|   reaches exactly (resolved_at, resolved_by); INSERT cannot name them;
|   created_at is filed hygiene (app-writable).
| - DELETE revoked on every customer table incl. the S20 pair (Doc 01 BR-02).
*/

beforeEach(function (): void {
    // The backfill suite re-opens the legacy window via the migrator toggle;
    // this suite asserts the ENFORCED schema, so it closes it first.
    legacyIdentityShapeEnforce();
});

const DOC08_TABLES = [
    'customer_notes', 'customer_merge_snapshots', 'rg_flag_history',
    'customer_identity_conflicts', 'customer_block_cascade_items',
];

/** The filed denied set per table AND PER PRIVILEGE (sorted). */
const DOC08_DENIED_COLUMNS = [
    'customer_notes' => [
        'INSERT' => ['created_at'],
        // The GRANT DELTA (step 6): customer_id joined the grant — FR-12
        // re-points notes and FS-02 re-points them back; content stays fenced.
        'UPDATE' => ['author_id', 'body', 'created_at', 'id', 'tenant_id'],
    ],
    'customer_merge_snapshots' => [
        'INSERT' => ['created_at', 'undone_at'],
        'UPDATE' => ['created_at', 'expires_at', 'id', 'merged_id', 'payload', 'survivor_id', 'tenant_id'],
    ],
    'rg_flag_history' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['action', 'actor_id', 'created_at', 'customer_id', 'excerpt_ref', 'id', 'reason', 'source', 'tenant_id'],
    ],
    'customer_identity_conflicts' => [
        'INSERT' => ['resolved_at', 'resolved_by'],
        'UPDATE' => ['created_at', 'id', 'keeper_customer_id', 'loser_customer_id', 'tenant_id', 'type', 'value'],
    ],
    // Amendment addition 7 (step 7): the marker pattern's third application.
    'customer_block_cascade_items' => [
        'INSERT' => ['restored_at'],
        'UPDATE' => ['conversation_id', 'created_at', 'customer_id', 'id', 'tenant_id'],
    ],
];

/** Ordering timestamps that must carry the clock_timestamp() default (R-19b). */
const DOC08_CLOCK_DEFAULT = [
    'customer_notes' => 'created_at',
    'customer_merge_snapshots' => 'created_at',
    'rg_flag_history' => 'created_at',
];

function doc08AppHasTablePrivilege(string $table, string $privilege): bool
{
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_table_privilege('app', ?, ?) AS ok",
        [$table, $privilege],
    );

    return (bool) $rows[0]->ok;
}

/** @return array{0: list<string>, 1: list<string>} [granted, denied], denied sorted */
function doc08AppColumnSplit(string $table, string $privilege): array
{
    /** @var list<object{column_name: string}> $columns */
    $columns = DB::connection('pgsql_migrator')->select(
        'SELECT column_name FROM information_schema.columns
          WHERE table_schema = ? AND table_name = ?
          ORDER BY ordinal_position',
        ['public', $table],
    );

    expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table} — the derivation below would assert nothing");

    $granted = [];
    $denied = [];

    foreach ($columns as $column) {
        /** @var list<object{ok: bool}> $rows */
        $rows = DB::connection('pgsql_migrator')->select(
            "SELECT has_column_privilege('app', ?, ?, ?) AS ok",
            [$table, $column->column_name, $privilege],
        );

        if ((bool) $rows[0]->ok) {
            $granted[] = $column->column_name;
        } else {
            $denied[] = $column->column_name;
        }
    }

    sort($denied);

    return [$granted, $denied];
}

it('R-19b: the app-denied column set of every Doc 08 table matches the countersigned amendment exactly, INSERT and UPDATE', function (): void {
    foreach (DOC08_TABLES as $table) {
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC08_DENIED_COLUMNS[$table][$privilege];
            sort($expected);

            [, $denied] = doc08AppColumnSplit($table, $privilege);

            expect($denied)->toBe($expected, sprintf(
                '%s %s: app-denied columns are [%s], the filed analysis says [%s] — a column moved without the amendment moving',
                $table, $privilege, implode(', ', $denied), implode(', ', $expected),
            ));
        }
    }
});

it('rg_flag_history is INSERT-ONLY for app — no UPDATE or DELETE at either the table or any column (AC-05 physics)', function (): void {
    // INSERT is COLUMN-granted (created_at excluded), so has_table_privilege is
    // false by design — has_any_column_privilege is the honest question.
    /** @var list<object{ok: bool}> $insert */
    $insert = DB::connection('pgsql_migrator')->select(
        "SELECT has_any_column_privilege('app', 'rg_flag_history', 'INSERT') AS ok",
    );
    /** @var list<object{ok: bool}> $update */
    $update = DB::connection('pgsql_migrator')->select(
        "SELECT has_any_column_privilege('app', 'rg_flag_history', 'UPDATE') AS ok",
    );

    expect((bool) $insert[0]->ok)->toBeTrue('app cannot insert history rows at all')
        ->and((bool) $update[0]->ok)->toBeFalse('an UPDATE grant reached the insert-only history')
        ->and(doc08AppHasTablePrivilege('rg_flag_history', 'DELETE'))->toBeFalse('a DELETE grant reached the insert-only history');
});

it('DELETE is revoked for app on every customer table, the S20 pair included (Doc 01 BR-02)', function (): void {
    foreach (array_merge(DOC08_TABLES, ['customers', 'customer_identities']) as $table) {
        expect(doc08AppHasTablePrivilege($table, 'DELETE'))
            ->toBeFalse("app can DELETE on {$table} — BR-02's revoke is gone");
    }
});

it('the three ordering timestamps carry the clock_timestamp() default (R-19b db-time-only)', function (): void {
    foreach (DOC08_CLOCK_DEFAULT as $table => $column) {
        /** @var list<object{column_default: ?string}> $rows */
        $rows = DB::connection('pgsql_migrator')->select(
            'SELECT column_default FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            ['public', $table, $column],
        );

        // toContain takes needles only (no message arg — the first run's
        // misuse turned messages into phantom needles; filed).
        expect($rows)->toHaveCount(1)
            ->and((string) $rows[0]->column_default)->toContain('clock_timestamp()');
    }
});

it('every Doc 08 table has FORCED row-level security with the tenant_isolation policy', function (): void {
    foreach (DOC08_TABLES as $table) {
        /** @var list<object{relrowsecurity: bool, relforcerowsecurity: bool}> $rows */
        $rows = DB::connection('pgsql_migrator')->select(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
            [$table],
        );

        expect($rows)->toHaveCount(1)
            ->and((bool) $rows[0]->relrowsecurity)->toBeTrue("{$table}: RLS not enabled")
            ->and((bool) $rows[0]->relforcerowsecurity)->toBeTrue("{$table}: RLS not FORCED — the owner is exempt");

        /** @var list<object{polname: string}> $policies */
        $policies = DB::connection('pgsql_migrator')->select(
            'SELECT polname FROM pg_policy WHERE polrelid = ?::regclass',
            [$table],
        );

        expect(array_column($policies, 'polname'))->toContain('tenant_isolation');
    }
});

it('type/value/source and customers.created_source are NOT NULL after enforce (SCHEMA AMENDMENT 2 item 2)', function (): void {
    foreach ([['customer_identities', 'type'], ['customer_identities', 'value'], ['customer_identities', 'source'], ['customers', 'created_source']] as [$table, $column]) {
        /** @var list<object{is_nullable: string}> $rows */
        $rows = DB::connection('pgsql_migrator')->select(
            'SELECT is_nullable FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            ['public', $table, $column],
        );

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->is_nullable)->toBe('NO');
    }
});

it('the (tenant, type, value) unique index exists on customer_identities (BR-04 physics — sabotage #3 subject)', function (): void {
    /** @var list<object{indexdef: string}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT indexdef FROM pg_indexes WHERE tablename = 'customer_identities' AND indexname = 'customer_identities_type_value_unique'",
    );

    expect($rows)->toHaveCount(1, 'the BR-04 unique index is gone')
        ->and($rows[0]->indexdef)->toContain('UNIQUE')
        ->and($rows[0]->indexdef)->toContain('tenant_id, type, value');
});

it('ADDITION 8: the arbiter is the ONLY non-PK unique index on customer_identities — the raw-23505 class is STRUCTURALLY eliminated (T-02\'s guarantee restored)', function (): void {
    // ON CONFLICT with a named arbiter handles conflicts on THAT index only.
    // The step-8 canonical red proved a race loser can hit a NON-arbiter
    // unique index mid-statement and throw raw 23505, bypassing the graceful
    // RaceLost path — a window between the winner's per-index insertions
    // that no external hook can freeze, so the proof is STRUCTURAL: the only
    // unique indexes are the PK (a fresh UUIDv7 per insert — collision
    // impossible) and the arbiter (handled). No third source can exist.
    /** @var list<object{indexname: string}> $unique */
    $unique = DB::connection('pgsql_migrator')->select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'customer_identities' AND indexdef LIKE 'CREATE UNIQUE%' ORDER BY indexname",
    );

    expect(array_column($unique, 'indexname'))->toBe(
        ['customer_identities_pkey', 'customer_identities_type_value_unique'],
        'a non-arbiter unique index stands on customer_identities — ON CONFLICT cannot handle its conflicts and T-02\'s race guarantee is breached (the legacy match_unique defect)',
    );

    // The probe: the exact scenario that raised raw 23505 pre-drop — a
    // conflict visible ONLY to the legacy (channel, external_user_id) pair.
    // The resolver-shaped upsert must proceed (no exception): nothing but
    // the arbiter constrains it now. Divergent state is test-planted; real
    // writers cannot produce it (the mirror invariant + migration check).
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $planted = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::statement(
        "INSERT INTO customer_identities (id, tenant_id, customer_id, channel, external_user_id, type, value, source, first_seen_at, metadata, created_at, updated_at)
         VALUES (?, ?, ?, 'telegram', 'add8-probe-1', 'telegram', 'add8-DIVERGENT', 'channel_auto', clock_timestamp(), '{}', now(), now())",
        [(string) Illuminate\Support\Str::uuid7(), $tenantId, $planted],
    );

    $other = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    /** @var object{id: string}|null $row */
    $row = DB::selectOne(
        <<<'SQL'
        INSERT INTO customer_identities
            (id, tenant_id, customer_id, channel, external_user_id,
             type, value, source, first_seen_at, metadata, created_at, updated_at)
        VALUES (?, ?, ?, 'telegram', 'add8-probe-1', 'telegram', 'add8-probe-1',
                'channel_auto', clock_timestamp(), '{}', now(), now())
        ON CONFLICT (tenant_id, type, value) DO NOTHING
        RETURNING id
        SQL,
        [(string) Illuminate\Support\Str::uuid7(), $tenantId, $other],
    );

    expect($row)->not->toBeNull('the arbiter refused a non-conflicting insert — wrong index in play');
});

it('THE GRANT DELTA\'S REPLACEMENT CONTROL (step-6 countersign): customer_notes.customer_id is written ONLY by the merge machinery — a stray re-point anywhere else fails here, not silently in production', function (): void {
    // The delta surrendered a piece of physics (the column grant); this
    // architecture assertion is the proven boundary that replaces it. A note
    // carries an author, a timestamp and PII — moving one to the wrong
    // customer must be a red test, never a quiet line of code.
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        if (! str_contains($contents, 'customer_notes')) {
            continue;
        }
        // Any update statement on customer_notes that names customer_id.
        if (preg_match('/customer_notes.{0,400}?->update\([^)]*customer_id/s', $contents) === 1
            || preg_match('/UPDATE\s+customer_notes.{0,200}customer_id/si', $contents) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe(['app/Domain/Customers/Merge/CustomerMerge.php']);
});
