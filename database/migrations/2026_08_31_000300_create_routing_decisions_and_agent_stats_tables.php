<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA AMENDMENT 5, tables 10–11 (countersigned 2026-08-30).
 *
 * routing_decisions (FR-17, BR-06, AC-08, SEC-02, FS-04) — INSERT-ONLY BY GRANT: no
 * UPDATE grant at any level, no DELETE. The audit surface is physics. `at` is
 * ORDERING-relevant (the timeline sorts by it) → DB time, app-unwritable both doors
 * (R-19a). `record` carries user IDS only, never names (SEC-02's elision is a render-time
 * join — absence, not redaction). NO `status` column, by design: the ticket-status-writer
 * gate's per-file heuristic (status assignment + a Ticket:: reference) cannot trip on a
 * Routing file that reads tickets.
 *
 * agent_assignment_stats (FR-05, FR-06, FR-10, EC-01, EC-03, AC-03) — the SELECT … FOR
 * UPDATE target of assignBySystem's commit-time capacity check; rebuildable from tickets
 * (routing:rebuild, FS-01). FLAG F (approved): last_assigned_at is FR-10's tiebreak →
 * ordering-relevant → app-unwritable, stamped by a migrator-owned PLAIN trigger (never
 * SECURITY DEFINER; R-20's four-definer ceiling untouched; writes NEW.* only) when
 * open_assigned increases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE routing_decisions (
                id             uuid        NOT NULL PRIMARY KEY,
                tenant_id      uuid        NOT NULL REFERENCES tenants (id),
                ticket_id      uuid        NOT NULL REFERENCES tickets (id),
                queue_id       uuid,
                department_id  uuid,
                trigger        text        NOT NULL,
                outcome        text        NOT NULL,
                assigned_to    uuid,
                strategy       text,
                eligible_count integer,
                record         jsonb       NOT NULL,
                snapshot       jsonb       NOT NULL,
                at             timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                created_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT routing_decisions_trigger_check CHECK (trigger IN (
                    'created', 'transferred', 'unassigned', 'deactivated', 'reopened', 'automation',
                    'pull', 'availability', 'manual', 'rescue', 'orphan', 'engine_error'
                )),
                CONSTRAINT routing_decisions_outcome_check CHECK (outcome IN ('assigned', 'waiting', 'parked_error', 'manual'))
            )
        SQL);
        DB::statement('CREATE INDEX routing_decisions_ticket_at ON routing_decisions (tenant_id, ticket_id, at DESC)');
        DB::statement('CREATE INDEX routing_decisions_queue_at ON routing_decisions (tenant_id, queue_id, at DESC)');

        DB::statement(<<<'SQL'
            CREATE TABLE agent_assignment_stats (
                id                        uuid        NOT NULL PRIMARY KEY,
                tenant_id                 uuid        NOT NULL REFERENCES tenants (id),
                user_id                   uuid        NOT NULL REFERENCES users (id),
                open_assigned             integer     NOT NULL DEFAULT 0,
                pending_customer_assigned integer     NOT NULL DEFAULT 0,
                last_assigned_at          timestamptz(6),
                created_at                timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at                timestamptz(6),
                CONSTRAINT agent_assignment_stats_unique UNIQUE (tenant_id, user_id),
                CONSTRAINT agent_assignment_stats_nonneg CHECK (open_assigned >= 0 AND pending_customer_assigned >= 0)
            )
        SQL);

        // FLAG F: the tiebreak stamp is the database's, never the app's.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION agent_assignment_stats_stamp() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF NEW.open_assigned > OLD.open_assigned THEN
                    NEW.last_assigned_at := clock_timestamp();
                ELSE
                    NEW.last_assigned_at := OLD.last_assigned_at;
                END IF;
                RETURN NEW;
            END;
            $fn$;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agent_assignment_stats_stamp_trigger
                BEFORE UPDATE ON agent_assignment_stats
                FOR EACH ROW
                EXECUTE FUNCTION agent_assignment_stats_stamp()
            SQL);

        foreach (['routing_decisions', 'agent_assignment_stats'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
        }

        // INSERT-ONLY BY GRANT: no UPDATE at any level, no DELETE.
        DB::statement('GRANT INSERT (id, tenant_id, ticket_id, queue_id, department_id, trigger, outcome, assigned_to, strategy, eligible_count, record, snapshot) ON routing_decisions TO app');

        DB::statement('GRANT INSERT (id, tenant_id, user_id, open_assigned, pending_customer_assigned, updated_at) ON agent_assignment_stats TO app');
        DB::statement('GRANT UPDATE (open_assigned, pending_customer_assigned, updated_at) ON agent_assignment_stats TO app');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS agent_assignment_stats_stamp_trigger ON agent_assignment_stats');
        DB::unprepared('DROP FUNCTION IF EXISTS agent_assignment_stats_stamp()');
        Schema::dropIfExists('agent_assignment_stats');
        Schema::dropIfExists('routing_decisions');
    }
};
