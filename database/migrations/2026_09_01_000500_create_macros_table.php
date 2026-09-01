<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 item 10 (countersigned 2026-09-01): macros & saved replies
 * (Doc 12 FR-14/FR-16; B12-S03's one-list-with-type-tag — same storage, `kind`).
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split:
 *   INSERT (id, tenant_id, scope, owner_id, team_id, kind, name, body, variables,
 *           bundled_actions, active) — usage_count is born zero, never supplied;
 *   UPDATE (scope, owner_id, team_id, kind, name, body, variables, bundled_actions,
 *           usage_count, active, updated_at);
 *   NO DELETE — retire via active=false (usage events reference by id).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE macros (
                id              uuid           NOT NULL PRIMARY KEY,
                tenant_id       uuid           NOT NULL REFERENCES tenants (id),
                scope           text           NOT NULL,
                owner_id        uuid,
                team_id         uuid           REFERENCES teams (id),
                kind            text           NOT NULL DEFAULT 'macro',
                name            text           NOT NULL,
                body            text           NOT NULL,
                variables       jsonb          NOT NULL DEFAULT '[]'::jsonb,
                bundled_actions jsonb,
                usage_count     integer        NOT NULL DEFAULT 0,
                active          boolean        NOT NULL DEFAULT true,
                created_at      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT macros_scope_check CHECK (scope IN ('personal', 'team', 'tenant')),
                CONSTRAINT macros_kind_check CHECK (kind IN ('macro', 'saved_reply')),
                CONSTRAINT macros_personal_owner_check CHECK (scope <> 'personal' OR owner_id IS NOT NULL),
                CONSTRAINT macros_team_ref_check CHECK (scope <> 'team' OR team_id IS NOT NULL)
            )
        SQL);
        DB::statement('CREATE INDEX macros_palette ON macros (tenant_id, scope, active)');

        DB::statement('ALTER TABLE macros ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE macros FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON macros
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
        SQL);
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON macros FROM app');
        DB::statement('GRANT SELECT ON macros TO app');
        DB::statement('GRANT INSERT (id, tenant_id, scope, owner_id, team_id, kind, name, body, variables, bundled_actions, active) ON macros TO app');
        DB::statement('GRANT UPDATE (scope, owner_id, team_id, kind, name, body, variables, bundled_actions, usage_count, active, updated_at) ON macros TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS macros');
    }
};
