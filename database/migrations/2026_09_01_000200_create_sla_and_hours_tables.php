<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 items 5–9 + RIDER 1 (countersigned 2026-09-01; rider filed before
 * this file — the B1-split law): SLA policies/instances/stages + business calendars
 * (Doc 12 FR-08/FR-09/FR-10/FR-12, BR-03, T-05, EC-02/03/05, NFR-02, FS-02, SS-02).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split per table:
 *   sla_policies               INSERT+UPDATE business columns; NO DELETE (deactivate).
 *   sla_instances              INSERT ONLY — NO UPDATE AT ALL, NO DELETE: BR-03's "armed
 *                              instances are contracts" is grant physics; armed_at app-DENIED.
 *   sla_instance_stages        INSERT (id, tenant_id, instance_id, stage, target_us,
 *                              clock_start_at); UPDATE (target_at, warned, breached,
 *                              late_fired, cleared_at, pauses, retargeted);
 *                              warned_at/breached_at trigger-stamped FILL-ONLY on the
 *                              rider's boolean flips; NO DELETE.
 *   business_calendars         INSERT+UPDATE business columns; NO DELETE (retire = active=false).
 *   business_calendar_holidays INSERT+UPDATE+DELETE — the amendment's one deliberate
 *                              DELETE grant: holidays are prospective-only list content
 *                              (EC-05: armed instances hold snapshot calendars, so a
 *                              removed holiday can never touch a running clock) and a
 *                              mis-typed holiday has no history obligation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE business_calendars (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                name              text           NOT NULL,
                timezone          text           NOT NULL,
                weekdays          jsonb          NOT NULL DEFAULT '{}'::jsonb,
                is_tenant_default boolean        NOT NULL DEFAULT false,
                department_id     uuid           REFERENCES departments (id),
                active            boolean        NOT NULL DEFAULT true,
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX business_calendars_tenant_default ON business_calendars (tenant_id) WHERE is_tenant_default');
        DB::statement('CREATE UNIQUE INDEX business_calendars_department ON business_calendars (tenant_id, department_id) WHERE department_id IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE business_calendar_holidays (
                id               uuid           NOT NULL PRIMARY KEY,
                tenant_id        uuid           NOT NULL REFERENCES tenants (id),
                calendar_id      uuid           NOT NULL REFERENCES business_calendars (id),
                date             date           NOT NULL,
                name             text           NOT NULL,
                recurring_yearly boolean        NOT NULL DEFAULT false,
                created_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE INDEX business_calendar_holidays_calendar ON business_calendar_holidays (tenant_id, calendar_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE sla_policies (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                name              text           NOT NULL,
                position          integer        NOT NULL,
                match_conditions  jsonb          NOT NULL DEFAULT '{}'::jsonb,
                targets           jsonb          NOT NULL DEFAULT '{}'::jsonb,
                clock_mode        text           NOT NULL,
                calendar_id       uuid           REFERENCES business_calendars (id),
                warning_pct       smallint       NOT NULL DEFAULT 80,
                pause_conditions  jsonb          NOT NULL DEFAULT '{"pending_customer": true, "pending_internal": false, "channel_unreachable": true}'::jsonb,
                retarget_mode     text           NOT NULL DEFAULT 'keep',
                breach_escalation jsonb,
                active            boolean        NOT NULL DEFAULT true,
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sla_policies_clock_mode_check CHECK (clock_mode IN ('business_hours', 'calendar')),
                CONSTRAINT sla_policies_calendar_required_check CHECK (clock_mode = 'calendar' OR calendar_id IS NOT NULL),
                CONSTRAINT sla_policies_warning_pct_check CHECK (warning_pct >= 1 AND warning_pct <= 100),
                CONSTRAINT sla_policies_retarget_mode_check CHECK (retarget_mode IN ('keep', 'retarget_remaining'))
            )
        SQL);
        DB::statement('CREATE INDEX sla_policies_order ON sla_policies (tenant_id, position, id)');

        DB::statement(<<<'SQL'
            CREATE TABLE sla_instances (
                id                uuid           NOT NULL PRIMARY KEY,
                tenant_id         uuid           NOT NULL REFERENCES tenants (id),
                ticket_id         uuid           NOT NULL REFERENCES tickets (id),
                policy_id         uuid           NOT NULL REFERENCES sla_policies (id),
                policy_snapshot   jsonb          NOT NULL,
                calendar_snapshot jsonb,
                armed_at          timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sla_instances_one_per_ticket ON sla_instances (tenant_id, ticket_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE sla_instance_stages (
                id             uuid           NOT NULL PRIMARY KEY,
                tenant_id      uuid           NOT NULL REFERENCES tenants (id),
                instance_id    uuid           NOT NULL REFERENCES sla_instances (id),
                stage          text           NOT NULL,
                target_us      bigint         NOT NULL,
                clock_start_at timestamptz(6) NOT NULL,
                target_at      timestamptz(6),
                warned         boolean        NOT NULL DEFAULT false,
                warned_at      timestamptz(6),
                breached       boolean        NOT NULL DEFAULT false,
                breached_at    timestamptz(6),
                late_fired     boolean        NOT NULL DEFAULT false,
                cleared_at     timestamptz(6),
                pauses         jsonb          NOT NULL DEFAULT '[]'::jsonb,
                retargeted     jsonb,
                created_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT sla_instance_stages_stage_check CHECK (stage IN ('first_response', 'next_response', 'resolution')),
                CONSTRAINT sla_instance_stages_target_check CHECK (target_us > 0)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX sla_instance_stages_live ON sla_instance_stages (tenant_id, instance_id, stage) WHERE cleared_at IS NULL');
        DB::statement('CREATE INDEX sla_instance_stages_due ON sla_instance_stages (tenant_id, target_at) WHERE cleared_at IS NULL AND target_at IS NOT NULL');

        // RIDER 1: warned_at/breached_at are FILL-ONLY, stamped when their app-writable
        // boolean flips false -> true; a flipped boolean never un-flips and a stamp never
        // moves (FS-02's honest detection instant — the EVENT carries the computed due_at).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sla_stages_stamp_flips() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF OLD.warned THEN
                    NEW.warned := true;
                    NEW.warned_at := OLD.warned_at;
                ELSIF NEW.warned THEN
                    NEW.warned_at := clock_timestamp();
                END IF;
                IF OLD.breached THEN
                    NEW.breached := true;
                    NEW.breached_at := OLD.breached_at;
                ELSIF NEW.breached THEN
                    NEW.breached_at := clock_timestamp();
                END IF;
                RETURN NEW;
            END
            $fn$;
            CREATE TRIGGER sla_instance_stages_stamp_flips
                BEFORE UPDATE ON sla_instance_stages
                FOR EACH ROW EXECUTE FUNCTION sla_stages_stamp_flips();
        SQL);

        foreach (['business_calendars', 'business_calendar_holidays', 'sla_policies', 'sla_instances', 'sla_instance_stages'] as $table) {
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

        DB::statement('GRANT INSERT (id, tenant_id, name, timezone, weekdays, is_tenant_default, department_id, active) ON business_calendars TO app');
        DB::statement('GRANT UPDATE (name, timezone, weekdays, is_tenant_default, department_id, active, updated_at) ON business_calendars TO app');

        DB::statement('GRANT INSERT (id, tenant_id, calendar_id, date, name, recurring_yearly) ON business_calendar_holidays TO app');
        DB::statement('GRANT UPDATE (date, name, recurring_yearly, updated_at) ON business_calendar_holidays TO app');
        DB::statement('GRANT DELETE ON business_calendar_holidays TO app');

        DB::statement('GRANT INSERT (id, tenant_id, name, position, match_conditions, targets, clock_mode, calendar_id, warning_pct, pause_conditions, retarget_mode, breach_escalation, active) ON sla_policies TO app');
        DB::statement('GRANT UPDATE (name, position, match_conditions, targets, clock_mode, calendar_id, warning_pct, pause_conditions, retarget_mode, breach_escalation, active, updated_at) ON sla_policies TO app');

        DB::statement('GRANT INSERT (id, tenant_id, ticket_id, policy_id, policy_snapshot, calendar_snapshot) ON sla_instances TO app');
        // sla_instances: NO UPDATE grant of any kind — BR-03 as physics.

        DB::statement('GRANT INSERT (id, tenant_id, instance_id, stage, target_us, clock_start_at) ON sla_instance_stages TO app');
        DB::statement('GRANT UPDATE (target_at, warned, breached, late_fired, cleared_at, pauses, retargeted, updated_at) ON sla_instance_stages TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sla_instance_stages');
        DB::statement('DROP FUNCTION IF EXISTS sla_stages_stamp_flips()');
        DB::statement('DROP TABLE IF EXISTS sla_instances');
        DB::statement('DROP TABLE IF EXISTS sla_policies');
        DB::statement('DROP TABLE IF EXISTS business_calendar_holidays');
        DB::statement('DROP TABLE IF EXISTS business_calendars');
    }
};
