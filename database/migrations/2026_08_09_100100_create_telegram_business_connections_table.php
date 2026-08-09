<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business connection registry (Doc 05 FR-07…FR-10, SEC-04, EC-01/02/05/11).
 *
 * Shape decisions and their spec anchors:
 * - One row per Telegram connection_id (EC-02: two admins converge on ONE
 *   registry row; EC-11: duplicate business_connection updates upsert by
 *   connection_id, idempotent) — unique (tenant_id, connection_id).
 * - SEC-04: rows are born `pending` and NEVER route until an admin confirms
 *   the account identity; the 10-minute alert keys on `created_at` (DB time).
 *   `confirmed_by`/`confirmed_at` are admin-action bookkeeping — app-written,
 *   NOT ordering/cursor/metric-bearing (stated per the plan's R-19b analysis).
 * - Lifecycle states change ONLY from business_connection updates (FR-09).
 *   `last_state_changed_at` is the NFR-04 metric — stamped exclusively by the
 *   BEFORE UPDATE trigger below (the R-20 pattern: plain plpgsql, migrator-
 *   owned, never SECURITY DEFINER, writes NEW.* only), column revoked from
 *   the app on both doors.
 * - `last_inbound_at` is a PROPAGATED DB-time reading (the inbound message
 *   row's clock_timestamp() stamp copied at processing) — app-writable by
 *   necessity; its provenance is pinned by test at step 6, never a wall clock.
 * - `sends_blocked_count` is a counter, not a time-derived value — app-written
 *   (the tickets.reopened_count precedent).
 * - DELETE stays GRANTED as a DECISION, not an omission: EC-01's wrong-account
 *   rejection deletes the row and restarts the checklist — the connection
 *   events are the history. Asserted explicitly in TelegramGrantPhysicsTest
 *   so removing or silently extending the exception both go red.
 * - R-19b: `created_at` (registry listing cursor) — default + both-door
 *   revoke + guard.
 */
return new class extends Migration
{
    /** Every column EXCEPT the DB-time set. */
    private const APP_WRITABLE_COLUMNS = [
        'id', 'tenant_id', 'bot_id', 'connection_id', 'account_user_id',
        'account_name', 'account_username', 'user_chat_id', 'state',
        'can_reply', 'rights', 'confirmed_by', 'confirmed_at',
        'last_inbound_at', 'sends_blocked_count', 'updated_at',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_business_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('bot_id');
            $table->text('connection_id');           // Telegram's business_connection.id
            $table->bigInteger('account_user_id');   // the support account's user id
            $table->text('account_name');            // display; EC-05 updates forward
            $table->text('account_username')->nullable();
            $table->bigInteger('user_chat_id');

            $table->text('state')->default('pending'); // SEC-04: pending until confirmed
            $table->boolean('can_reply')->default(false);
            $table->jsonb('rights')->nullable();

            $table->uuid('confirmed_by')->nullable();
            $table->timestampTz('confirmed_at', 6)->nullable();

            $table->timestampTz('last_state_changed_at', 6)->default(DB::raw('clock_timestamp()')); // trigger-stamped
            $table->timestampTz('last_inbound_at', 6)->nullable(); // propagated DB time
            $table->integer('sends_blocked_count')->default(0);

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()')); // R-19b
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bot_id')->references('id')->on('telegram_bots');
            $table->foreign('confirmed_by')->references('id')->on('users');

            $table->unique(['tenant_id', 'connection_id']); // EC-02/EC-11 physics
            $table->index(['tenant_id', 'bot_id', 'state']);
        });

        DB::statement("ALTER TABLE telegram_business_connections ADD CONSTRAINT telegram_business_connections_state_check CHECK (state IN ('pending', 'active', 'paused', 'revoked'))");

        // The R-20 pattern: plain (never SECURITY DEFINER) migrator-owned
        // function; BEFORE UPDATE; writes NEW.* only; stamps the NFR-04 metric
        // in DB time exclusively on a real state transition.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION telegram_connections_stamp_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state IS DISTINCT FROM OLD.state THEN
                    NEW.last_state_changed_at := clock_timestamp();
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER telegram_connections_stamp_transition
                BEFORE UPDATE ON telegram_business_connections
                FOR EACH ROW EXECUTE FUNCTION telegram_connections_stamp_transition()
            SQL);

        // R-19a/R-19b: created_at and the trigger-stamped metric cannot be
        // NAMED by the app role, either door. DELETE deliberately stays
        // granted (EC-01 — see the docblock).
        $columns = implode(', ', self::APP_WRITABLE_COLUMNS);
        DB::statement('REVOKE INSERT ON telegram_business_connections FROM app');
        DB::statement("GRANT INSERT ({$columns}) ON telegram_business_connections TO app");
        DB::statement('REVOKE UPDATE ON telegram_business_connections FROM app');
        DB::statement("GRANT UPDATE ({$columns}) ON telegram_business_connections TO app");

        DB::statement('ALTER TABLE telegram_business_connections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_business_connections FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON telegram_business_connections
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_business_connections');
        DB::statement('DROP FUNCTION IF EXISTS telegram_connections_stamp_transition()');
    }
};
