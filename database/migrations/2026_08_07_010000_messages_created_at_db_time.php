<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner ruling R-19 + advisor addendum R-19a; ADR 0010.
 *
 * WHY. `messages.created_at` is the leading component of FR-07's display order and
 * T-08's cursor, and it was written by Eloquent from the APP-SERVER wall clock,
 * truncated to whole seconds by the Postgres grammar's `Y-m-d H:i:s`. The table already
 * declared `DEFAULT now()`, but Eloquent supplied a value on every insert so the default
 * never applied. That violates C-11 ("ordering decisions use DB time or UUIDv7
 * monotonicity — never app-server wall clocks"): a backward clock step larger than the
 * gap between two same-lane messages reorders a customer's thread, and under Octane
 * ordinary skew between two app servers is enough on its own.
 *
 * WHY `clock_timestamp()` AND NOT `now()`. `now()`/`CURRENT_TIMESTAMP` return
 * TRANSACTION-START time, so every row written inside one transaction would share one
 * identical stamp — which would make ties the norm rather than the exception and push
 * all ordering onto the UUIDv7 tiebreak. `clock_timestamp()` reads the clock per row.
 * Verified on this stack before writing this migration (three rows inserted in ONE
 * transaction received three distinct microsecond stamps).
 *
 * THE GRANT IS THE POINT (R-19a). A DEFAULT is overridable: any future code that names
 * the column silently wins and the defect returns with no test noticing. CLAUDE.md §1
 * requires physics, not discipline, so `app` LOSES the ability to name the column.
 * Postgres has no "revoke one column" for a role holding table-level INSERT, so this is
 * expressed as REVOKE-then-GRANT-a-column-list — the same form the RLS migration already
 * uses for `tenant_keys`. A role that cannot name `created_at` cannot supply it; the
 * default still fills it.
 *
 * `platform_admin` deliberately KEEPS table-level INSERT: writing historical rows is a
 * capability the retention and partition-routing suites must retain, and they already
 * write through `pgsql_platform`.
 *
 * PARTITIONS. Every write goes through the parent (verified: nothing inserts into a
 * monthly partition directly), so the parent's default and the parent's grant are what
 * Postgres checks. An `ALTER ... SET DEFAULT` on the parent also propagates to existing
 * partitions, and partitions created later by `MessagePartitions` copy the parent's
 * default at creation time — which is why this migration must land before the next
 * partition is created.
 *
 * EXPAND-CONTRACT (C-12). Metadata only: no table rewrite, no backfill, no column drop,
 * so there is no contract phase to schedule. Historical rows keep their whole-second
 * values (R-19 §5); mixed precision compares correctly.
 */
return new class extends Migration
{
    /**
     * Every column of `messages` EXCEPT `created_at` — the set the app may INSERT and
     * UPDATE. Kept explicit rather than derived: a new column must be added here
     * deliberately, and forgetting fails loudly on the first write instead of silently
     * re-opening the hole this migration closes. GrantPhysicsTest derives the same split
     * from information_schema and fails if the two ever disagree.
     */
    private const APP_WRITABLE_COLUMNS = [
        'id', 'tenant_id', 'conversation_id', 'direction', 'author_type', 'author_id',
        'kind', 'body', 'media', 'language', 'external_message_id', 'reply_to_message_id',
        'delivery_state', 'delivery_error', 'is_internal', 'quarantined', 'edited_at',
        'deleted_at', 'client_idempotency_key', 'channel_meta', 'channel_occurred_at',
        'updated_at',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE messages ALTER COLUMN created_at SET DEFAULT clock_timestamp()');

        // THE FK'S TWO SIDES MUST AGREE ON PRECISION.
        // `messages.created_at` was declared in raw SQL as bare `timestamptz`, i.e.
        // microseconds. `message_revisions.message_created_at` was declared through
        // Laravel's `timestampTz()`, whose precision argument DEFAULTS TO 0 — whole
        // seconds. That mismatch was invisible while the app wrote whole seconds into
        // both. The moment `created_at` became real DB time, a revision's copy rounded
        // to the nearest second and the composite FK
        // (message_id, message_created_at) -> messages (id, created_at) pointed at a row
        // that did not exist: "Key is not present in table messages" for a message
        // plainly sitting there. Found by EditDeleteTranscriptTest and
        // NodeKillReplayDrillTest on the first full-suite run after the cutover.
        // Widening is binary-coercible, so no table rewrite and no backfill.
        DB::statement('ALTER TABLE message_revisions ALTER COLUMN message_created_at TYPE timestamptz(6)');
        DB::statement('ALTER TABLE message_revisions ALTER COLUMN created_at TYPE timestamptz(6)');

        $columns = implode(', ', self::APP_WRITABLE_COLUMNS);
        DB::statement('REVOKE INSERT ON messages FROM app');
        DB::statement("GRANT INSERT ({$columns}) ON messages TO app");

        // UPDATE TOO — created_at IS IMMUTABLE.
        // Revoking only INSERT left the hole open from the other side: the app could
        // still UPDATE the column afterwards, which reaches exactly the same bad state
        // (an ordering key set from an app clock) while the INSERT-only guard reported
        // everything fine. There is no legitimate reason for the application to move a
        // message's receipt time, so it cannot.
        DB::statement('REVOKE UPDATE ON messages FROM app');
        DB::statement("GRANT UPDATE ({$columns}) ON messages TO app");
    }

    public function down(): void
    {
        DB::statement('GRANT INSERT ON messages TO app');
        DB::statement('GRANT UPDATE ON messages TO app');
        DB::statement('ALTER TABLE message_revisions ALTER COLUMN created_at TYPE timestamptz(0)');
        DB::statement('ALTER TABLE message_revisions ALTER COLUMN message_created_at TYPE timestamptz(0)');
        DB::statement('ALTER TABLE messages ALTER COLUMN created_at SET DEFAULT now()');
    }
};
