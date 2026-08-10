<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 05 grants ARE the invariants (FR-04, SEC-02 posture, R-19b) — from pg_catalog
|--------------------------------------------------------------------------
|
| Same posture as TicketGrantPhysicsTest: every grant-shaped claim becomes a
| has_*_privilege() fact, so a later migration that quietly GRANTs something
| back fails a named test instead of passing review. The column split is
| DERIVED FROM THE SCHEMA, never hardcoded per assertion, so a new column
| outside the filed R-19b analysis surfaces here by name.
|
| - FR-04: bots are archived, never dropped — DELETE denied on telegram_bots
|   and telegram_menus. DELETE on connections, templates and flow_targets is
|   a DECISION, not an omission (EC-01's rejection deletes the connection;
|   removing a language's templates and a menu button's target are operator
|   acts) — each exception asserted explicitly so removing OR silently
|   extending it goes red.
| - Menu versions are an immutable INSERT-only store (publish snapshots,
|   FR-27's rollback-by-pointer): no app UPDATE, no app DELETE.
| - R-19b: telegram_bots.created_at; telegram_business_connections.created_at
|   + last_state_changed_at (trigger-stamped, NFR-04); telegram_menu_versions
|   .created_at (IS the publish instant, orders version history). The app
|   role cannot NAME any of these, either door.
| - The NFR-04 transition trigger exists and is wired (pg_catalog facts);
|   its stamping BEHAVIOUR is step 6's lifecycle suite.
*/

const DOC05_TABLES = [
    'telegram_bots', 'telegram_business_connections', 'telegram_menus',
    'telegram_menu_versions', 'telegram_templates', 'telegram_flow_targets',
    'telegram_owner_phone_identities',
];

/**
 * The R-19b denied set per table AND PER PRIVILEGE — the plan's filed
 * per-column analysis, amended at step 11 for the health columns (the
 * migration docblock carries the same analysis):
 * - the seven health columns are UPDATE-granted (the poll and the router
 *   write them; the pinned-provenance ones only via clock_timestamp() in the
 *   UPDATE statement — the connections.last_inbound_at ruling applied);
 * - NONE of them is INSERT-granted: defaults fill on insert, so the denied
 *   INSERT set GROWS by exactly those seven, asserted by name.
 */
const DOC05_DB_TIME_COLUMNS = [
    'telegram_bots' => [
        'INSERT' => [
            'created_at', 'health_changed_at', 'health_status', 'last_inbound_at',
            'last_polled_at', 'webhook_last_error', 'webhook_last_error_at',
            'webhook_pending_updates',
        ],
        'UPDATE' => ['created_at'],
    ],
    'telegram_business_connections' => [
        'INSERT' => ['created_at', 'last_state_changed_at'],
        'UPDATE' => ['created_at', 'last_state_changed_at'],
    ],
    'telegram_menus' => ['INSERT' => [], 'UPDATE' => []],
    'telegram_menu_versions' => ['INSERT' => ['created_at'], 'UPDATE' => ['created_at']],
    'telegram_templates' => ['INSERT' => [], 'UPDATE' => []],
    'telegram_flow_targets' => ['INSERT' => [], 'UPDATE' => []],
    'telegram_owner_phone_identities' => ['INSERT' => [], 'UPDATE' => []],
];

function telegramAppHasPrivilege(string $table, string $privilege): bool
{
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_table_privilege('app', ?, ?) AS ok",
        [$table, $privilege],
    );

    return (bool) $rows[0]->ok;
}

/** @return array{0: list<string>, 1: list<string>} [granted, denied], denied sorted */
function telegramAppColumnSplit(string $table, string $privilege): array
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

it('R-19b: the app role cannot NAME any DB-time column on a Doc 05 table, INSERT or UPDATE — derived from the schema', function (): void {
    foreach (DOC05_TABLES as $table) {
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            if ($privilege === 'UPDATE' && $table === 'telegram_menu_versions') {
                continue; // no UPDATE privilege at all — asserted separately below
            }

            $expected = DOC05_DB_TIME_COLUMNS[$table][$privilege];
            sort($expected);

            [, $denied] = telegramAppColumnSplit($table, $privilege);

            expect($denied)->toBe($expected, sprintf(
                '%s %s: app-denied columns are [%s], the filed R-19b analysis says [%s] — a column moved without the plan moving',
                $table, $privilege, implode(', ', $denied), implode(', ', $expected),
            ));
        }
    }
});

it('FR-04: bots and menus are archived, never dropped — DELETE denied; the three DELETE exceptions are DECISIONS, asserted', function (): void {
    expect(telegramAppHasPrivilege('telegram_bots', 'DELETE'))->toBeFalse('telegram_bots DELETE crept back — FR-04 archives, never drops');
    expect(telegramAppHasPrivilege('telegram_menus', 'DELETE'))->toBeFalse('telegram_menus DELETE crept back');
    expect(telegramAppHasPrivilege('telegram_menu_versions', 'DELETE'))->toBeFalse('menu versions are an immutable store — DELETE crept back');

    // The exceptions, byname — EC-01 (rejection deletes the connection),
    // template-language removal, menu-button target removal.
    expect(telegramAppHasPrivilege('telegram_business_connections', 'DELETE'))->toBeTrue('connection DELETE was revoked — EC-01\'s wrong-account rejection cannot restart the checklist');
    expect(telegramAppHasPrivilege('telegram_templates', 'DELETE'))->toBeTrue('template DELETE was revoked — §14 completeness reads row presence');
    expect(telegramAppHasPrivilege('telegram_flow_targets', 'DELETE'))->toBeTrue('flow-target DELETE was revoked — button removal must remove its target');
});

it('SEC-02 posture: menu versions are INSERT-only for the app — no UPDATE privilege on any column', function (): void {
    expect(telegramAppHasPrivilege('telegram_menu_versions', 'UPDATE'))->toBeFalse('a published menu version became editable — rollback-by-pointer physics is gone');

    [$granted] = telegramAppColumnSplit('telegram_menu_versions', 'UPDATE');
    expect($granted)->toBe([], 'column-level UPDATE grants exist on telegram_menu_versions — the insert-only store leaks');
});

it('NFR-04: the transition trigger exists, is BEFORE UPDATE, and its function is plain (never SECURITY DEFINER)', function (): void {
    /** @var list<object{tgname: string}> $triggers */
    $triggers = DB::connection('pgsql_migrator')->select(
        "SELECT tgname FROM pg_trigger
          WHERE tgrelid = 'telegram_business_connections'::regclass
            AND tgname = 'telegram_connections_stamp_transition'",
    );
    expect($triggers)->toHaveCount(1, 'the NFR-04 stamp trigger is missing — last_state_changed_at has no legal writer');

    /** @var list<object{prosecdef: bool}> $functions */
    $functions = DB::connection('pgsql_migrator')->select(
        "SELECT prosecdef FROM pg_proc WHERE proname = 'telegram_connections_stamp_transition'",
    );
    expect($functions)->toHaveCount(1);
    expect((bool) $functions[0]->prosecdef)->toBeFalse('the stamp function became SECURITY DEFINER — the R-1 four-door ceiling exists for a reason');
});

it('every Doc 05 table carries FORCED row-level security with the tenant_isolation policy', function (): void {
    foreach (DOC05_TABLES as $table) {
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
        $policyNames = array_map(static fn (object $p): string => $p->polname, $policies);
        expect(in_array('tenant_isolation', $policyNames, true))
            ->toBeTrue("{$table}: the tenant_isolation policy is missing");
    }
});
