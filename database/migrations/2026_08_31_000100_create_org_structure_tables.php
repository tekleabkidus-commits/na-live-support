<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA AMENDMENT 5, tables 1–6 (countersigned 2026-08-30; Doc 11 T-01, FR-01…FR-04,
 * FR-18, EC-10, BR-04, SEC-03, T-07, EC-07): departments, teams, team_members, queues,
 * queue_teams, queue_members.
 *
 * - DB time only (C-11, R-19b): created_at/updated_at default clock_timestamp(); app
 *   INSERT-denied on created_at everywhere; ended_at is UPDATE-only (a row is never BORN
 *   ended — the KbGrantPhysicsTest "deleted_at" disclosure, applied here to every
 *   tombstone/ended column).
 * - FLAG A (approved): departments.default_queue_id is NULLABLE with a deferrable FK added
 *   AFTER queues exists; EC-10's "defaultless department" refusal lives at the SERVICE
 *   door (DepartmentWriter) and the seeder creates department + default queue in ONE
 *   transaction — the DB never observes a defaultless department except as broken data,
 *   which FR-16c's net owns.
 * - FLAG B (approved): the starvation cap is structurally ABSENT from queues.priority_rules
 *   (setting + engine only — StarvationCapPropertyTest).
 * - FLAG C (approved): queue_members has NO DELETE grant — a direct removal also writes
 *   ended_at (uniform "ended" semantics; EC-07's persistence holds for both kinds).
 * - FLAG D (approved): queue_teams is its own table — FR-03's "via team" link; a
 *   nullable team_id on queue_members would break the UNIQUE + CHECK physics.
 * - The SINGLE SOURCE (SEC-03, T-01): a temporary queue_members row cannot exist without
 *   its Doc 03 override row and an expiry — CHECK physics, not convention.
 * - is_rg at most one per tenant: partial UNIQUE INDEX (FR-03, the Doc 09 FR-13 target).
 * - REVOKE DELETE on departments/teams/queues (FR-18: delete = tombstone after the wizard
 *   moved every reference; SS-03's "zero orphaned tickets" is physics).
 * - No hot table is touched (ci/migration-safety.php); schema-builder indexes are fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE departments (
                id                          uuid        NOT NULL PRIMARY KEY,
                tenant_id                   uuid        NOT NULL REFERENCES tenants (id),
                name                        jsonb       NOT NULL,
                slug                        text        NOT NULL,
                description                 jsonb,
                position                    integer     NOT NULL DEFAULT 0,
                default_queue_id            uuid,
                business_hours_override_ref text,
                is_active                   boolean     NOT NULL DEFAULT true,
                seeded                      boolean     NOT NULL DEFAULT false,
                seeded_fingerprint          text,
                deleted_at                  timestamptz(6),
                created_at                  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at                  timestamptz(6)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX departments_tenant_slug_live ON departments (tenant_id, slug) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX departments_tenant_position ON departments (tenant_id, position)');

        DB::statement(<<<'SQL'
            CREATE TABLE teams (
                id          uuid        NOT NULL PRIMARY KEY,
                tenant_id   uuid        NOT NULL REFERENCES tenants (id),
                name        text        NOT NULL,
                description text,
                deleted_at  timestamptz(6),
                created_at  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at  timestamptz(6)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX teams_tenant_name_live ON teams (tenant_id, name) WHERE deleted_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE team_members (
                id         uuid        NOT NULL PRIMARY KEY,
                tenant_id  uuid        NOT NULL REFERENCES tenants (id),
                team_id    uuid        NOT NULL REFERENCES teams (id),
                user_id    uuid        NOT NULL REFERENCES users (id),
                is_lead    boolean     NOT NULL DEFAULT false,
                created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz(6),
                CONSTRAINT team_members_unique UNIQUE (tenant_id, team_id, user_id)
            )
        SQL);
        DB::statement('CREATE INDEX team_members_user ON team_members (tenant_id, user_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE queues (
                id                  uuid        NOT NULL PRIMARY KEY,
                tenant_id           uuid        NOT NULL REFERENCES tenants (id),
                department_id       uuid        NOT NULL REFERENCES departments (id),
                name                text        NOT NULL,
                position            integer     NOT NULL DEFAULT 0,
                assignment_strategy text        NOT NULL DEFAULT 'round_robin',
                capacity_per_agent  smallint,
                priority_rules      jsonb       NOT NULL DEFAULT '[]'::jsonb,
                skill_matchers      jsonb       NOT NULL DEFAULT '[]'::jsonb,
                skill_fallback      text        NOT NULL DEFAULT 'nearest_skill',
                routing_tags        text[]      NOT NULL DEFAULT '{}',
                is_rg               boolean     NOT NULL DEFAULT false,
                is_active           boolean     NOT NULL DEFAULT true,
                rr_pointer_user_id  uuid,
                deleted_at          timestamptz(6),
                created_at          timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at          timestamptz(6),
                CONSTRAINT queues_strategy_check CHECK (assignment_strategy IN ('round_robin', 'load_based', 'skill_based')),
                CONSTRAINT queues_fallback_check CHECK (skill_fallback IN ('nearest_skill', 'wait')),
                CONSTRAINT queues_capacity_check CHECK (capacity_per_agent IS NULL OR capacity_per_agent > 0)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX queues_tenant_department_name_live ON queues (tenant_id, department_id, name) WHERE deleted_at IS NULL');
        // FR-03: "is_rg (at most one per tenant)" — physics, not a service rule.
        DB::statement('CREATE UNIQUE INDEX queues_one_rg_per_tenant ON queues (tenant_id) WHERE is_rg AND deleted_at IS NULL');
        DB::statement('CREATE INDEX queues_tags_gin ON queues USING gin (routing_tags)');
        DB::statement('CREATE INDEX queues_tenant_department_position ON queues (tenant_id, department_id, position)');

        // FLAG A: the circular FK, deferrable so a department and its default queue commit together.
        DB::statement('ALTER TABLE departments ADD CONSTRAINT departments_default_queue_fk FOREIGN KEY (default_queue_id) REFERENCES queues (id) DEFERRABLE INITIALLY DEFERRED');

        DB::statement(<<<'SQL'
            CREATE TABLE queue_teams (
                id         uuid        NOT NULL PRIMARY KEY,
                tenant_id  uuid        NOT NULL REFERENCES tenants (id),
                queue_id   uuid        NOT NULL REFERENCES queues (id),
                team_id    uuid        NOT NULL REFERENCES teams (id),
                created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT queue_teams_unique UNIQUE (tenant_id, queue_id, team_id)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE queue_members (
                id          uuid        NOT NULL PRIMARY KEY,
                tenant_id   uuid        NOT NULL REFERENCES tenants (id),
                queue_id    uuid        NOT NULL REFERENCES queues (id),
                user_id     uuid        NOT NULL REFERENCES users (id),
                kind        text        NOT NULL,
                granted_by  uuid        REFERENCES users (id),
                override_id uuid        REFERENCES user_permission_overrides (id),
                expires_at  timestamptz(6),
                ended_at    timestamptz(6),
                created_at  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at  timestamptz(6),
                CONSTRAINT queue_members_kind_check CHECK (kind IN ('direct', 'temporary')),
                -- THE SINGLE SOURCE (T-01/SEC-03): temporary ⇔ a Doc 03 grant row AND an expiry.
                CONSTRAINT queue_members_temporary_single_source CHECK (
                    ((kind = 'temporary') = (override_id IS NOT NULL))
                    AND ((kind = 'temporary') = (expires_at IS NOT NULL))
                )
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX queue_members_live_unique ON queue_members (tenant_id, queue_id, user_id) WHERE ended_at IS NULL');
        DB::statement('CREATE INDEX queue_members_user_live ON queue_members (tenant_id, user_id) WHERE ended_at IS NULL');
        DB::statement('CREATE INDEX queue_members_expiry ON queue_members (tenant_id, expires_at) WHERE kind = \'temporary\' AND ended_at IS NULL');

        foreach (['departments', 'teams', 'team_members', 'queues', 'queue_teams', 'queue_members'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
        }

        // ADR 0003 grant physics — the amendment's filed splits, verbatim.
        DB::statement('GRANT INSERT (id, tenant_id, name, slug, description, position, default_queue_id, business_hours_override_ref, is_active, seeded, seeded_fingerprint, updated_at) ON departments TO app');
        DB::statement('GRANT UPDATE (name, slug, description, position, default_queue_id, business_hours_override_ref, is_active, seeded_fingerprint, deleted_at, updated_at) ON departments TO app');

        DB::statement('GRANT INSERT (id, tenant_id, name, description, updated_at) ON teams TO app');
        DB::statement('GRANT UPDATE (name, description, deleted_at, updated_at) ON teams TO app');

        DB::statement('GRANT INSERT (id, tenant_id, team_id, user_id, is_lead, updated_at) ON team_members TO app');
        DB::statement('GRANT UPDATE (is_lead, updated_at) ON team_members TO app');
        DB::statement('GRANT DELETE ON team_members TO app');

        DB::statement('GRANT INSERT (id, tenant_id, department_id, name, position, assignment_strategy, capacity_per_agent, priority_rules, skill_matchers, skill_fallback, routing_tags, is_rg, is_active, rr_pointer_user_id, updated_at) ON queues TO app');
        DB::statement('GRANT UPDATE (department_id, name, position, assignment_strategy, capacity_per_agent, priority_rules, skill_matchers, skill_fallback, routing_tags, is_rg, is_active, rr_pointer_user_id, deleted_at, updated_at) ON queues TO app');

        DB::statement('GRANT INSERT (id, tenant_id, queue_id, team_id) ON queue_teams TO app');
        DB::statement('GRANT DELETE ON queue_teams TO app');

        DB::statement('GRANT INSERT (id, tenant_id, queue_id, user_id, kind, granted_by, override_id, expires_at, updated_at) ON queue_members TO app');
        DB::statement('GRANT UPDATE (ended_at, updated_at) ON queue_members TO app');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE departments DROP CONSTRAINT IF EXISTS departments_default_queue_fk');
        foreach (['queue_members', 'queue_teams', 'queues', 'team_members', 'teams', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
