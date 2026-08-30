<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA AMENDMENT 5, tables 7–9 (countersigned 2026-08-30): user_departments (A-50 —
 * Doc 03 FR-03's "departments the user belongs to", a membership OF ITS OWN, independent
 * of queue membership by table; FR-04's config error is representable because of it),
 * user_skills (FR-11, T-05, R-04), user_routing_capacity (FR-06 routing.capacity.user).
 *
 * - user_departments carries the SAME single-source CHECK as queue_members (a temporary
 *   department access cannot exist without its Doc 03 override + expiry) and, per FLAG C,
 *   NO DELETE grant — access ends by ended_at.
 * - FLAG E (approved): user_skills.reviewed_at is app-writable (a human act's stamp).
 * - user_routing_capacity: DELETE GRANTED (removing an override restores the queue
 *   default; audited routing.capacity_override.removed).
 * - Users are tenant-scoped rows (Doc 02); the tenant_id column + RLS keep every read
 *   inside the tenant even where the FK alone would not.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE user_departments (
                id            uuid        NOT NULL PRIMARY KEY,
                tenant_id     uuid        NOT NULL REFERENCES tenants (id),
                user_id       uuid        NOT NULL REFERENCES users (id),
                department_id uuid        NOT NULL REFERENCES departments (id),
                kind          text        NOT NULL,
                granted_by    uuid        REFERENCES users (id),
                override_id   uuid        REFERENCES user_permission_overrides (id),
                expires_at    timestamptz(6),
                ended_at      timestamptz(6),
                created_at    timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at    timestamptz(6),
                CONSTRAINT user_departments_kind_check CHECK (kind IN ('direct', 'temporary')),
                CONSTRAINT user_departments_temporary_single_source CHECK (
                    ((kind = 'temporary') = (override_id IS NOT NULL))
                    AND ((kind = 'temporary') = (expires_at IS NOT NULL))
                )
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX user_departments_live_unique ON user_departments (tenant_id, user_id, department_id) WHERE ended_at IS NULL');
        DB::statement('CREATE INDEX user_departments_expiry ON user_departments (tenant_id, expires_at) WHERE kind = \'temporary\' AND ended_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE user_skills (
                id             uuid        NOT NULL PRIMARY KEY,
                tenant_id      uuid        NOT NULL REFERENCES tenants (id),
                user_id        uuid        NOT NULL REFERENCES users (id),
                languages      text[]      NOT NULL DEFAULT '{}',
                department_ids uuid[]      NOT NULL DEFAULT '{}',
                reviewed_at    timestamptz(6),
                created_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at     timestamptz(6),
                CONSTRAINT user_skills_unique UNIQUE (tenant_id, user_id)
            )
        SQL);
        DB::statement('CREATE INDEX user_skills_languages_gin ON user_skills USING gin (languages)');

        DB::statement(<<<'SQL'
            CREATE TABLE user_routing_capacity (
                id         uuid        NOT NULL PRIMARY KEY,
                tenant_id  uuid        NOT NULL REFERENCES tenants (id),
                user_id    uuid        NOT NULL REFERENCES users (id),
                capacity   smallint    NOT NULL,
                set_by     uuid        NOT NULL REFERENCES users (id),
                reason     text,
                created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz(6),
                CONSTRAINT user_routing_capacity_unique UNIQUE (tenant_id, user_id),
                CONSTRAINT user_routing_capacity_positive CHECK (capacity > 0)
            )
        SQL);

        foreach (['user_departments', 'user_skills', 'user_routing_capacity'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
        }

        DB::statement('GRANT INSERT (id, tenant_id, user_id, department_id, kind, granted_by, override_id, expires_at, updated_at) ON user_departments TO app');
        DB::statement('GRANT UPDATE (ended_at, updated_at) ON user_departments TO app');

        DB::statement('GRANT INSERT (id, tenant_id, user_id, languages, department_ids, reviewed_at, updated_at) ON user_skills TO app');
        DB::statement('GRANT UPDATE (languages, department_ids, reviewed_at, updated_at) ON user_skills TO app');

        DB::statement('GRANT INSERT (id, tenant_id, user_id, capacity, set_by, reason, updated_at) ON user_routing_capacity TO app');
        DB::statement('GRANT UPDATE (capacity, set_by, reason, updated_at) ON user_routing_capacity TO app');
        DB::statement('GRANT DELETE ON user_routing_capacity TO app');
    }

    public function down(): void
    {
        foreach (['user_routing_capacity', 'user_skills', 'user_departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
