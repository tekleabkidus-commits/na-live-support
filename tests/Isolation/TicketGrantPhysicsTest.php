<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 grants ARE the invariants (BR-01, SEC-02, R-19b) — asserted from pg_catalog
|--------------------------------------------------------------------------
|
| Same posture as GrantPhysicsTest (Doc 01): every grant-shaped claim becomes a
| has_*_privilege() fact, so a later migration that quietly GRANTs something back
| fails a named test instead of passing review.
|
| - BR-01: tickets are never deleted in v1 — DELETE denied on every Doc 06 table
|   EXCEPT ticket_followers, where DELETE is a DECISION, not an omission: unfollowing
|   genuinely IS deleting the membership row (the tenant_settings precedent), and the
|   follower_added/removed events are the history. The exception is asserted
|   explicitly so removing it — or silently extending it — both go red.
| - SEC-02: handoff packets and unmerge snapshots are immutable INSERT-ONLY stores —
|   no app UPDATE at all, proven as an absence of privilege, then behaviourally.
| - R-19b (ADR 0010, rulings R-19/R-19a): the DB-time/metric columns of `tickets` and
|   `ticket_merge_snapshots.created_at` cannot be NAMED by the app role, INSERT or
|   UPDATE. The column split is DERIVED FROM THE SCHEMA, never hardcoded, so a new
|   column outside the filed interpretation surfaces here by name (the messages
|   guard's rationale, per R-19b "same shape, per table").
*/

const DOC06_TABLES = [
    'tickets', 'ticket_followers', 'ticket_handoff_packets', 'ticket_merge_snapshots',
    'ticket_bulk_jobs', 'ticket_bulk_job_items', 'ticket_message_anchors',
];

/** The R-19b denied set on tickets — every DB-time or metric-bearing column. */
const TICKETS_DB_TIME_COLUMNS = [
    'agent_first_response_at', 'closed_at', 'created_at', 'first_response_at',
    'last_status_changed_at', 'resolved_at', 'spam_marked_at',
    'waiting_customer_seconds', 'waiting_staff_seconds',
];

function ticketAppHasPrivilege(string $table, string $privilege): bool
{
    /** @var list<object{ok: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select(
        "SELECT has_table_privilege('app', ?, ?) AS ok",
        [$table, $privilege],
    );

    return (bool) $rows[0]->ok;
}

/** @return array{0: list<string>, 1: list<string>} [granted, denied], denied sorted */
function ticketAppColumnSplit(string $table, string $privilege): array
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

it('denies the app role DELETE on every Doc 06 table except ticket_followers (BR-01/N-05)', function (string $table) {
    expect(ticketAppHasPrivilege($table, 'DELETE'))->toBeFalse(
        "SEV-1: the app role can DELETE from {$table} — BR-01's never-deleted physics is gone",
    );
})->with(array_values(array_diff(DOC06_TABLES, ['ticket_followers'])));

it('keeps DELETE on ticket_followers as the one deliberate exception (unfollow IS row deletion)', function () {
    // If this goes red, someone revoked it — fine, but then FR-18's unfollow needs a
    // new mechanism and this test documents where the decision lived.
    expect(ticketAppHasPrivilege('ticket_followers', 'DELETE'))->toBeTrue(
        'ticket_followers lost DELETE — unfollow (FR-18) has no path; this exception is documented in the migration and here',
    );
});

it('denies the app role UPDATE entirely on the immutable stores (SEC-02: insert-only by physics)', function (string $table) {
    expect(ticketAppHasPrivilege($table, 'UPDATE'))->toBeFalse(
        "SEV-1: the app role can UPDATE {$table} — SEC-02's immutability is politeness now, not physics",
    );
})->with(['ticket_handoff_packets', 'ticket_merge_snapshots']);

it('denies the app role INSERT and UPDATE on exactly the tickets DB-time/metric columns (R-19b)', function (string $privilege) {
    [, $denied] = ticketAppColumnSplit('tickets', $privilege);

    expect($denied)->toBe(TICKETS_DB_TIME_COLUMNS, sprintf(
        'the app role must be unable to %s EXACTLY the DB-time/metric columns of tickets. Denied: [%s]. '
        .'A missing name re-opens the C-11 hole R-19 closed; an extra name means a column left the filed '
        .'interpretation (A-7) without going through the record.',
        $privilege,
        implode(', ', $denied),
    ));
})->with(['INSERT', 'UPDATE']);

it('fills every DB-time column from clock_timestamp() and never from now() (R-19b, advisor strengthening 2)', function () {
    // THE GRANTS SAY THE APP CANNOT WRITE THESE COLUMNS. This says what fills them
    // INSTEAD — and the two together are the whole R-19b guarantee: unwritable by the
    // app, filled by the database's per-row clock.
    //
    // WHY now() WOULD BE A DEFECT, NOT A STYLE CHOICE. now()/CURRENT_TIMESTAMP return
    // TRANSACTION-START time, so every ticket created in one transaction would share
    // one identical stamp and `last_status_changed_at` would be earlier than the row
    // itself — making the first accumulator interval measure from the transaction's
    // beginning rather than from the ticket's birth (FR-24). That is the same class of
    // defect R-19 fixed for messages, and it would be invisible: the column would be
    // populated, non-null, and plausible.
    //
    // Derived from the LIVE catalog via pg_get_expr, never from the migration file: a
    // later ALTER that changed a default would leave the migration reading correctly
    // while the database did something else.
    $defaults = DB::connection('pgsql_migrator')->select("
        SELECT a.attname AS column_name, pg_get_expr(d.adbin, d.adrelid) AS default_expr
          FROM pg_attribute a
          JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
         WHERE a.attrelid = 'public.tickets'::regclass
           AND a.attname IN ('created_at', 'last_status_changed_at')
    ");

    $byColumn = [];
    foreach ($defaults as $row) {
        $byColumn[$row->column_name] = (string) $row->default_expr;
    }

    // EVERY ASSERTION BELOW IS `expect(<bool>)->toBeTrue($message)`, deliberately.
    // Pest's toHaveKey($key, $value) reads its second argument as the expected VALUE
    // and toContain(...$needles) reads its as ANOTHER NEEDLE — neither takes a
    // message. Written the natural way, this test failed against a perfectly correct
    // schema, and worse, it was RED BEFORE the now() sabotage, so its red during the
    // sabotage proved nothing at all. Both runs were repeated after this fix. The
    // boolean-plus-message form has no such trap.
    foreach (['created_at', 'last_status_changed_at'] as $column) {
        expect(array_key_exists($column, $byColumn))->toBeTrue(
            "tickets.{$column} has NO default at all — nothing legal can fill a column the app cannot write",
        );

        $default = $byColumn[$column];

        expect(str_contains($default, 'clock_timestamp()'))->toBeTrue(sprintf(
            'tickets.%s defaults to "%s" — R-19b requires clock_timestamp()', $column, $default,
        ));

        // "never now()" as its own assertion, because a default such as
        // `COALESCE(now(), clock_timestamp())` would satisfy the line above.
        expect(str_contains($default, 'now()'))->toBeFalse(sprintf(
            'tickets.%s default mentions now() ("%s") — transaction-start time cannot order rows written together (C-11)',
            $column, $default,
        ));
    }

    // NOT NULL matters for the same reason: a nullable status clock would make the
    // first interval measure from nothing.
    $nullable = DB::connection('pgsql_migrator')->selectOne("
        SELECT is_nullable FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'tickets' AND column_name = 'last_status_changed_at'
    ");
    expect($nullable->is_nullable)->toBe('NO');
});

it('denies the app role INSERT on exactly ticket_merge_snapshots.created_at (R-19b: the 24 h window authority)', function () {
    [, $denied] = ticketAppColumnSplit('ticket_merge_snapshots', 'INSERT');

    expect($denied)->toBe(['created_at'],
        'the unmerge window (T-04/FS-04) must anchor on a DB-time stamp the app cannot supply. Denied: ['.implode(', ', $denied).']');
});

it('enables and FORCEs RLS with a policy on every Doc 06 table', function (string $table) {
    /** @var list<object{relrowsecurity: bool, relforcerowsecurity: bool, policies: int|string}> $rows */
    $rows = DB::connection('pgsql_migrator')->select("
        SELECT c.relrowsecurity, c.relforcerowsecurity,
               (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS policies
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relname = ?
    ", [$table]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
        ->and($rows[0]->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS")
        ->and((int) $rows[0]->policies)->toBeGreaterThan(0, "{$table} has no policy");
})->with(DOC06_TABLES);

// ── The grants behave, not only exist ────────────────────────────────────────

function grantPhysicsTicket(): array
{
    $tenant = createTenant();
    $customer = Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_grants',
        'is_shell' => true,
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenant->identifier(),
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.Str::random(8),
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);

    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticketId,
        'tenant_id' => $tenant->identifier(),
        'conversation_id' => $conversationId,
        'customer_id' => $customer->identifier(),
        'number' => random_int(1, 2_000_000_000),
        'status' => 'new',
        'priority' => 'normal',
        'source' => 'agent',
    ]);

    return [$tenant, $ticketId];
}

it('refuses an app insert that NAMES created_at, and an app update that moves a metric column', function () {
    [, $ticketId] = grantPhysicsTicket();

    expect(fn () => DB::transaction(fn () => DB::statement(
        "UPDATE tickets SET resolved_at = '2026-01-01T00:00:00Z' WHERE id = ?",
        [$ticketId],
    )))->toThrow(QueryException::class, 'permission denied');

    expect(fn () => DB::transaction(fn () => DB::statement(
        "INSERT INTO tickets (id, tenant_id, conversation_id, customer_id, number, status, priority, source, created_at)
         SELECT ?, tenant_id, conversation_id, customer_id, ?, 'closed', 'normal', 'agent', '2026-01-01T00:00:00Z'
           FROM tickets WHERE id = ?",
        [(string) Str::uuid7(), random_int(1, 2_000_000_000), $ticketId],
    )))->toThrow(QueryException::class, 'permission denied');
});

it('refuses an app DELETE behaviourally, not only in the catalog (BR-01)', function () {
    [, $ticketId] = grantPhysicsTicket();

    expect(fn () => DB::transaction(fn () => DB::statement(
        'DELETE FROM tickets WHERE id = ?',
        [$ticketId],
    )))->toThrow(QueryException::class, 'permission denied');
});
