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
    'customer_identity_conflicts',
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
