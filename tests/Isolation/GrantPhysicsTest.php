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
