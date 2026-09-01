<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 items 1–4 (countersigned 2026-09-01; filed to ANSWERS before this
 * file exists — the B1-split law): the automation-rules quartet (Doc 12 FR-01/FR-04/
 * FR-06/FR-07, SEC-01, SEC-03, BR-01, BR-05, R-05).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   automation_rules        INSERT+UPDATE business columns; NO DELETE (retire = active=false).
 *   automation_rule_revisions  INSERT (id, tenant_id, rule_id, definition, author_id);
 *                              UPDATE EXACTLY (activated_by, activator_capability_floor);
 *                              activated_at is trigger-stamped FILL-ONLY (R-20 shape); NO DELETE.
 *   automation_rule_executions INSERT-only (business columns); executed_at app-DENIED
 *                              (ordering + Doc 15 metrics); NO UPDATE, NO DELETE — the 90 d
 *                              retention purge is the platform role's, never app's.
 *   automation_keyword_sets    INSERT+UPDATE (name, keywords, active, updated_at); NO DELETE.
 *
 * BR-01 determinism: list order is ORDER BY position, id (UUIDv7 tiebreak) — position
 * carries an index, deliberately NOT a unique. The guard caps (10/20/depth 3) are code
 * constants, not columns (BR-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE automation_rules (
                id                   uuid           NOT NULL PRIMARY KEY,
                tenant_id            uuid           NOT NULL REFERENCES tenants (id),
                name                 text           NOT NULL,
                description          text,
                trigger              text           NOT NULL,
                position             integer        NOT NULL,
                active               boolean        NOT NULL DEFAULT false,
                active_revision_id   uuid,
                run_window           jsonb,
                schedule_cron        text,
                schedule_next_run_at timestamptz(6),
                cooldown_seconds     integer        NOT NULL DEFAULT 300,
                created_at           timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at           timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT automation_rules_trigger_check CHECK (trigger IN (
                    'ticket.created', 'ticket.status_changed', 'ticket.priority_changed',
                    'ticket.transferred', 'message.received', 'form.submitted',
                    'sla.warned', 'sla.breached', 'csat.received',
                    'telegram.window.closing', 'telegram.window.expired',
                    'customer.rg_flag.set', 'schedule'
                )),
                CONSTRAINT automation_rules_cooldown_check CHECK (cooldown_seconds >= 0 AND cooldown_seconds <= 86400)
            )
        SQL);
        DB::statement('CREATE INDEX automation_rules_order ON automation_rules (tenant_id, position, id)');
        DB::statement('CREATE INDEX automation_rules_trigger ON automation_rules (tenant_id, trigger) WHERE active');

        DB::statement(<<<'SQL'
            CREATE TABLE automation_rule_revisions (
                id                          uuid           NOT NULL PRIMARY KEY,
                tenant_id                   uuid           NOT NULL REFERENCES tenants (id),
                rule_id                     uuid           NOT NULL REFERENCES automation_rules (id),
                definition                  jsonb          NOT NULL,
                author_id                   uuid           NOT NULL,
                activated_by                uuid,
                activated_at                timestamptz(6),
                activator_capability_floor  jsonb,
                created_at                  timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE INDEX automation_rule_revisions_history ON automation_rule_revisions (tenant_id, rule_id, created_at DESC)');
        DB::statement(<<<'SQL'
            ALTER TABLE automation_rules
                ADD CONSTRAINT automation_rules_active_revision_fk
                FOREIGN KEY (active_revision_id) REFERENCES automation_rule_revisions (id)
        SQL);

        // FR-07's activation instant is FILL-ONLY (the R-20 shape): stamped by the
        // database when activated_by transitions NULL -> value, immovable afterwards.
        // A SEC-01 floor refresh is a NEW revision row, never a re-stamp (the writer
        // documents this; the trigger makes forgetting it harmless).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION automation_revisions_stamp_activation() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF OLD.activated_at IS NOT NULL THEN
                    NEW.activated_at := OLD.activated_at;
                    NEW.activated_by := OLD.activated_by;
                ELSIF OLD.activated_by IS NULL AND NEW.activated_by IS NOT NULL THEN
                    NEW.activated_at := clock_timestamp();
                END IF;
                RETURN NEW;
            END
            $fn$;
            CREATE TRIGGER automation_rule_revisions_stamp_activation
                BEFORE UPDATE ON automation_rule_revisions
                FOR EACH ROW EXECUTE FUNCTION automation_revisions_stamp_activation();
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE automation_rule_executions (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                rule_id           uuid           NOT NULL REFERENCES automation_rules (id),
                revision_id       uuid           NOT NULL REFERENCES automation_rule_revisions (id),
                event_id          uuid           NOT NULL,
                context_ref       jsonb          NOT NULL DEFAULT '{}'::jsonb,
                condition_results jsonb          NOT NULL DEFAULT '[]'::jsonb,
                action_outcomes   jsonb          NOT NULL DEFAULT '[]'::jsonb,
                guard_trips       jsonb          NOT NULL DEFAULT '[]'::jsonb,
                matched           boolean        NOT NULL,
                executed_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX automation_rule_executions_dedupe ON automation_rule_executions (tenant_id, rule_id, event_id)');
        DB::statement('CREATE INDEX automation_rule_executions_log ON automation_rule_executions (tenant_id, rule_id, executed_at DESC)');
        DB::statement('CREATE INDEX automation_rule_executions_event ON automation_rule_executions (tenant_id, event_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE automation_keyword_sets (
                id         uuid           NOT NULL PRIMARY KEY,
                tenant_id  uuid           NOT NULL REFERENCES tenants (id),
                name       text           NOT NULL,
                keywords   jsonb          NOT NULL DEFAULT '[]'::jsonb,
                active     boolean        NOT NULL DEFAULT true,
                created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);

        foreach (['automation_rules', 'automation_rule_revisions', 'automation_rule_executions', 'automation_keyword_sets'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
            DB::statement("GRANT SELECT ON {$table} TO app");
        }

        DB::statement('GRANT INSERT (id, tenant_id, name, description, trigger, position, active, active_revision_id, run_window, schedule_cron, schedule_next_run_at, cooldown_seconds) ON automation_rules TO app');
        DB::statement('GRANT UPDATE (name, description, trigger, position, active, active_revision_id, run_window, schedule_cron, schedule_next_run_at, cooldown_seconds, updated_at) ON automation_rules TO app');

        DB::statement('GRANT INSERT (id, tenant_id, rule_id, definition, author_id) ON automation_rule_revisions TO app');
        DB::statement('GRANT UPDATE (activated_by, activator_capability_floor) ON automation_rule_revisions TO app');

        DB::statement('GRANT INSERT (id, tenant_id, rule_id, revision_id, event_id, context_ref, condition_results, action_outcomes, guard_trips, matched) ON automation_rule_executions TO app');

        DB::statement('GRANT INSERT (id, tenant_id, name, keywords, active) ON automation_keyword_sets TO app');
        DB::statement('GRANT UPDATE (name, keywords, active, updated_at) ON automation_keyword_sets TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS automation_rule_executions');
        DB::statement('ALTER TABLE IF EXISTS automation_rules DROP CONSTRAINT IF EXISTS automation_rules_active_revision_fk');
        DB::statement('DROP TABLE IF EXISTS automation_rule_revisions');
        DB::statement('DROP FUNCTION IF EXISTS automation_revisions_stamp_activation()');
        DB::statement('DROP TABLE IF EXISTS automation_rules');
        DB::statement('DROP TABLE IF EXISTS automation_keyword_sets');
    }
};
