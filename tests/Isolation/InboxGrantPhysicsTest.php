<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 07 grants ARE the invariants (R-19b, T-05/SEC-06 physics, row 9) — from pg_catalog
|--------------------------------------------------------------------------
|
| Same posture as Ticket/TelegramGrantPhysicsTest: every grant-shaped claim
| becomes a has_*_privilege() fact, and the column split is DERIVED FROM THE
| SCHEMA, never hardcoded per assertion, so a column arriving outside the
| filed R-19b analysis surfaces here BY NAME.
|
| The filed analysis (approved plan §6 + S07 SCHEMA AMENDMENT 1):
| - ticket_list_items: created_at db-time-only; identity (ticket_id,
|   tenant_id) frozen after insert; the five pinned-provenance copies stay
|   app-writable BY DESIGN (the projector writes COPIES of canonical DB-time
|   stamps — the Doc 05 last_inbound_at class).
| - inbox_selection_tokens: mint-once — INSERT can name neither timestamp;
|   UPDATE reaches exactly the consumed_at latch (SEC-06's single-use is
|   physics before it is code).
| - inbox_notifications: created_at ORDERS the bell (full R-19b); UPDATE
|   reaches exactly the read_at latch. THIS IS SABOTAGE #11's TABLE.
| - saved views / user prefs / agent statuses / read markers: no
|   ordering/metric-bearing timestamp (filed analysis) — hygiene defaults,
|   whole-row app writes, DELETE still revoked everywhere (BR-02 posture;
|   saved-view delete is the deleted_at tombstone).
| - events (BUILD-STATUS row 9 DISCHARGED, correction C2): occurred_at is
|   db-time-only (clock_timestamp default), app INSERT-denied on exactly that
|   column, app UPDATE revoked outright — append-only for the app role. THIS
|   IS SABOTAGE #16's GUARD.
*/

const DOC07_TABLES = [
    'ticket_list_items', 'inbox_saved_views', 'inbox_selection_tokens',
    'inbox_notifications', 'inbox_user_prefs', 'agent_statuses',
    'inbox_read_markers',
];

/** The filed R-19b denied set per table AND PER PRIVILEGE (sorted). */
const DOC07_DENIED_COLUMNS = [
    'ticket_list_items' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['created_at', 'tenant_id', 'ticket_id'],
    ],
    'inbox_saved_views' => [
        'INSERT' => [],
        'UPDATE' => [],
    ],
    'inbox_selection_tokens' => [
        'INSERT' => ['consumed_at', 'created_at'],
        'UPDATE' => ['created_at', 'filter_snapshot', 'id', 'item_count', 'tenant_id', 'user_id', 'view_hash'],
    ],
    'inbox_notifications' => [
        'INSERT' => ['created_at', 'read_at'],
        'UPDATE' => ['category', 'conversation_id', 'created_at', 'id', 'payload', 'tenant_id', 'ticket_id', 'user_id'],
    ],
    'inbox_user_prefs' => [
        'INSERT' => [],
        'UPDATE' => [],
    ],
    'agent_statuses' => [
        'INSERT' => [],
        'UPDATE' => [],
    ],
    'inbox_read_markers' => [
        'INSERT' => [],
        'UPDATE' => [],
    ],
];

/** Tables whose created_at is db-time-only and must carry the clock_timestamp() default. */
const DOC07_CLOCK_DEFAULT = [
    'ticket_list_items' => 'created_at',
    'inbox_selection_tokens' => 'created_at',
    'inbox_notifications' => 'created_at',
    'events' => 'occurred_at',
];

function inboxAppHasPrivilege(string $table, string $privilege): bool
{
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_table_privilege('app', ?, ?) AS ok",
        [$table, $privilege],
    );

    return (bool) $rows[0]->ok;
}

/** @return array{0: list<string>, 1: list<string>} [granted, denied], denied sorted */
function inboxAppColumnSplit(string $table, string $privilege): array
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

it('R-19b: the app-denied column set of every Doc 07 table matches the filed analysis exactly, INSERT and UPDATE — derived from the schema', function (): void {
    foreach (DOC07_TABLES as $table) {
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC07_DENIED_COLUMNS[$table][$privilege];
            sort($expected);

            [, $denied] = inboxAppColumnSplit($table, $privilege);

            expect($denied)->toBe($expected, sprintf(
                '%s %s: app-denied columns are [%s], the filed R-19b analysis says [%s] — a column moved without the plan moving',
                $table, $privilege, implode(', ', $denied), implode(', ', $expected),
            ));
        }
    }
});

it('nothing Doc 07 stores is app-deletable — DELETE denied on all seven tables (BR-02 posture; saved-view delete is the tombstone)', function (): void {
    foreach (DOC07_TABLES as $table) {
        expect(inboxAppHasPrivilege($table, 'DELETE'))->toBeFalse("{$table}: DELETE crept back for app");
    }
});

it('every Doc 07 table carries FORCED row-level security with the tenant_isolation policy', function (): void {
    foreach (DOC07_TABLES as $table) {
        /** @var list<object{relrowsecurity: bool, relforcerowsecurity: bool}> $rls */
        $rls = DB::connection('pgsql_migrator')->select(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
            [$table],
        );
        expect($rls)->toHaveCount(1);
        expect((bool) $rls[0]->relrowsecurity)->toBeTrue("{$table}: RLS is not enabled");
        expect((bool) $rls[0]->relforcerowsecurity)->toBeTrue("{$table}: RLS is not FORCED — the owner role would bypass it");

        /** @var list<object{polname: string}> $policies */
        $policies = DB::connection('pgsql_migrator')->select(
            'SELECT polname FROM pg_policy WHERE polrelid = ?::regclass',
            [$table],
        );
        // NOT toContain($needle, $message): Pest's toContain takes VARIADIC
        // NEEDLES, so a "message" becomes a second required element — the S22
        // ledger-row-7b matcher trap, avoided by name here.
        expect(in_array('tenant_isolation', array_column($policies, 'polname'), true))
            ->toBeTrue("{$table}: the tenant_isolation policy is missing");
    }
});

it('the db-time defaults are clock_timestamp(), never now() — pg_attrdef facts (R-19/R-23 family; sabotage #16\'s guard for events)', function (): void {
    foreach (DOC07_CLOCK_DEFAULT as $table => $column) {
        /** @var list<object{def: string|null}> $rows */
        $rows = DB::connection('pgsql_migrator')->select(
            'SELECT pg_get_expr(d.adbin, d.adrelid) AS def
               FROM pg_attrdef d
               JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
              WHERE d.adrelid = ?::regclass AND a.attname = ?',
            [$table, $column],
        );
        expect($rows)->toHaveCount(1, "{$table}.{$column} has NO default — the db-time source is gone");
        $def = (string) $rows[0]->def;
        expect(str_contains($def, 'clock_timestamp()'))->toBeTrue(
            "{$table}.{$column} default is \"{$def}\" — not clock_timestamp(); now()/transaction-start cannot order rows (R-19)",
        );
    }
});

it('row 9 discharged (C2): events is append-only for app — INSERT denied on exactly [occurred_at], UPDATE revoked outright', function (): void {
    [, $deniedInsert] = inboxAppColumnSplit('events', 'INSERT');
    expect($deniedInsert)->toBe(['occurred_at'], sprintf(
        'events INSERT: app-denied columns are [%s], the row-9 treatment says exactly [occurred_at]',
        implode(', ', $deniedInsert),
    ));

    expect(inboxAppHasPrivilege('events', 'UPDATE'))->toBeFalse('events UPDATE crept back for app — the event log is append-only');
    [$grantedUpdate] = inboxAppColumnSplit('events', 'UPDATE');
    expect($grantedUpdate)->toBe([], 'column-level UPDATE grants exist on events — the append-only posture leaks');
});
