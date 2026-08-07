<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The grants ARE the invariants — asserted as facts in pg_catalog, not as behaviour
 * (Doc 01 BR-02, N-05, N-13, SEC-03, C-06/EC-02, NFR-08).
 *
 * S1's session report cited a hand-run "six tampering routes refused" table (ADR
 * 0003) as evidence for several of these. A hand-run is a demonstration, not a
 * regression net: a later migration could quietly GRANT DELETE back and nothing
 * would go red. This file is the wrap-up audit's answer — every grant-shaped claim
 * in Doc 01 becomes a has_table_privilege() assertion that runs on every push.
 */
function appHasPrivilege(string $table, string $privilege): bool
{
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_table_privilege('app', ?, ?) AS ok",
        [$table, $privilege],
    );

    return (bool) $rows[0]->ok;
}

it('denies the app role DELETE on every tenant-scoped table (BR-02/N-05: nothing hard-deletes)', function (string $table) {
    expect(appHasPrivilege($table, 'DELETE'))->toBeFalse(
        "SEV-1: the app role can DELETE from {$table} — BR-02's soft-delete-only physics is gone",
    );
})->with([
    'tenants', 'users', 'tenant_keys', 'tenant_sequences', 'tenant_provisioning_steps',
    'audit_log', 'outbox', 'events', 'tenant_quota_usage', 'tenant_cold_summaries',
    // Doc 03 (S19): roles soft-delete by ruling R-11; overrides and pending
    // grants revoke/transition, never vanish.
    'roles', 'user_permission_overrides', 'pending_grants',
]);

it('denies the app role UPDATE on audit_log (N-13: append-only by grants, not discipline)', function () {
    expect(appHasPrivilege('audit_log', 'UPDATE'))->toBeFalse(
        'SEV-1: the app role can UPDATE audit_log — an editable audit trail is a diary, not a record',
    );
});

it('denies the app role SELECT on the tenant_keys hash column (SEC-03: match a hash, never read one)', function () {
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_column_privilege('app', 'tenant_keys', 'hash', 'SELECT') AS ok",
    );

    expect((bool) $rows[0]->ok)->toBeFalse(
        'SEV-1: the app role can read stored key hashes — resolution must go through resolve_tenant_key()',
    );
});

it('ships no timestamp column without a timezone (NFR-08/C-03: everything is timestamptz, UTC)', function () {
    /** @var list<object{table_name: string, column_name: string}> $rows */
    $rows = DB::connection('pgsql_migrator')->select("
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND data_type = 'timestamp without time zone'
          AND table_name NOT LIKE 'pg_%'
    ");

    $offenders = array_map(
        static fn (object $row): string => $row->table_name.'.'.$row->column_name,
        $rows,
    );

    expect($offenders)->toBeEmpty(
        'timestamp WITHOUT time zone found — NFR-08 requires timestamptz everywhere: '.implode(', ', $offenders),
    );
});

it('keeps after_commit TRUE on the durable queue connection (C-06/EC-02: afterCommit is the default)', function () {
    // EC-02: a plain job dispatched inside a transaction that rolls back must not run.
    // Domain events get this from the outbox; plain jobs get it from this flag — which
    // is one config edit away from silently vanishing, hence the assertion.
    expect(config('queue.connections.redis.after_commit'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Doc 02 credential physics
|--------------------------------------------------------------------------
|
| Same posture as the rules above: what must be true of stored credentials is
| asserted from the CATALOG, so a future migration that reintroduces a
| readable secret fails a test rather than passing review.
|
*/

it('stores no recovery code in a readable form (SEC-03/BR-02/Q6: hashed, shown exactly once)', function () {
    // Fortify's stock storage is an ENCRYPTED-RETRIEVABLE column plus a
    // re-display route, which makes "displayed exactly once" a UI convention
    // rather than a fact. The hashed user_recovery_codes table replaced it —
    // this asserts the replacement stayed replaced.
    /** @var list<object{table_name: string, column_name: string}> $rows */
    $rows = DB::connection('pgsql_migrator')->select("
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND (column_name LIKE '%recovery_code%' OR column_name LIKE '%recovery_codes%')
    ");

    $offenders = [];
    foreach ($rows as $row) {
        // The ONE legitimate column is the argon2id digest.
        if ($row->table_name === 'user_recovery_codes' && $row->column_name === 'code_hash') {
            continue;
        }
        $offenders[] = $row->table_name.'.'.$row->column_name;
    }

    expect($offenders)->toBeEmpty(
        'SEV-1: a recovery-code column exists outside the hashed store — anything retrievable '
        .'can be re-displayed, and SEC-03 says exactly once: '.implode(', ', $offenders),
    );
});

it('keeps every credential column out of reach of a plaintext read (SEC-08)', function () {
    // A column whose NAME promises a hash but whose contents are not one is the
    // failure this cannot catch; what it CAN catch is the reintroduction of a
    // column that is plaintext by construction.
    $forbidden = [
        'users' => ['plain_password', 'password_plain', 'totp_secret_plain'],
        'auth_action_tokens' => ['token', 'token_plain', 'plaintext'],
        'user_recovery_codes' => ['code', 'code_plain'],
    ];

    foreach ($forbidden as $table => $columns) {
        foreach ($columns as $column) {
            /** @var list<object{present: bool}> $rows */
            $rows = DB::connection('pgsql_migrator')->select(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = \'public\' AND table_name = ? AND column_name = ?
                 ) AS present',
                [$table, $column],
            );

            expect((bool) $rows[0]->present)->toBeFalse(sprintf(
                'SEV-1: %s.%s exists — a credential stored in the clear (SEC-08).',
                $table,
                $column,
            ));
        }
    }
});

/**
 * R-19a / ADR 0010 — the app role cannot NAME messages.created_at.
 *
 * Owner ruling R-19 moved the thread ordering key's leading component to database time
 * (`clock_timestamp()`). A column DEFAULT alone would leave that resting on discipline:
 * any future code naming the column silently overrides it and the C-11 violation returns
 * with nothing going red. CLAUDE.md §1 wants physics, so the app role lost column-level
 * INSERT on `created_at` and cannot supply it at all.
 *
 * THE COLUMN LIST IS DERIVED FROM THE SCHEMA, NEVER HARDCODED. A fixed list of names
 * still passes when a later migration adds a column the R-19a grant never covered — the
 * app would lose INSERT on it and the failure would surface far away, with no trail back
 * to this ruling. Reading information_schema instead turns that into an immediate, named
 * failure here: the set of columns the app may not insert must be EXACTLY ['created_at'].
 */
function appInsertableColumnSplit(string $table): array
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
            "SELECT has_column_privilege('app', ?, ?, 'INSERT') AS ok",
            [$table, $column->column_name],
        );

        if ((bool) $rows[0]->ok) {
            $granted[] = $column->column_name;
        } else {
            $denied[] = $column->column_name;
        }
    }

    return [$granted, $denied];
}

it('denies the app role INSERT on messages.created_at and on nothing else (R-19a: DB time is physics, not discipline)', function () {
    [$granted, $denied] = appInsertableColumnSplit('messages');

    // One assertion covers both failure directions: created_at granted back (the C-11
    // violation returns), or a newly added column left out of the R-19a grant.
    expect($denied)->toBe(['created_at'], sprintf(
        'the app role must be unable to insert EXACTLY messages.created_at. Denied: [%s]. Granted: [%s]. '
        .'If created_at is missing from the denied list, R-19/ADR 0010 is broken and the ordering key is back on an '
        .'app-server wall clock. If anything ELSE appears there, a migration added a column the R-19a grant never '
        .'covered — add it to that grant, do not weaken this test.',
        implode(', ', $denied), implode(', ', $granted),
    ));
});

it('keeps the platform role able to write historical rows (retention and partition fixtures depend on it)', function () {
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_column_privilege('platform_admin', 'messages', 'created_at', 'INSERT') AS ok",
    );

    expect((bool) $rows[0]->ok)->toBeTrue(
        'platform_admin lost INSERT on messages.created_at — backdating historical rows is a capability the retention and partition suites must keep',
    );
});
