<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 — RIDER 3 (filed 2026-09-02, before this migration — the
 * rider law): form_flow_checkpoints, T-07's DB half (Doc 12 FR-21/EC-06/SS-03/
 * FS-04). The Redis half is volatile working state; THIS row is what a crashed
 * flow resumes from, checkpointed at step boundaries.
 *
 * Grant physics (ADR-0003 / R-19b), the APP_WRITABLE_COLUMNS split:
 *   INSERT (id, tenant_id, conversation_id, form_id, version_id, step_index,
 *           answers, language, mode);
 *   UPDATE (step_index, answers, outcome, finished_at, updated_at) —
 *           form_id/version_id/conversation_id/language/mode IMMUTABLE by grant
 *           (EC-06's pin: a mid-form publish can never move a running flow);
 *   NO DELETE — a superseded flow closes by outcome, never by vanishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE form_flow_checkpoints (
                id              uuid           NOT NULL PRIMARY KEY,
                tenant_id       uuid           NOT NULL REFERENCES tenants (id),
                conversation_id uuid           NOT NULL REFERENCES conversations (id),
                form_id         uuid           NOT NULL REFERENCES forms (id),
                version_id      uuid           NOT NULL REFERENCES form_versions (id),
                step_index      integer        NOT NULL DEFAULT 0,
                answers         jsonb          NOT NULL DEFAULT '{}'::jsonb,
                language        text           NOT NULL,
                mode            text           NOT NULL,
                outcome         text,
                finished_at     timestamptz(6),
                created_at      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                updated_at      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT form_flow_checkpoints_mode_check CHECK (mode IN ('direct', 'business')),
                CONSTRAINT form_flow_checkpoints_outcome_check CHECK (outcome IS NULL OR outcome IN ('completed', 'handed_off', 'abandoned', 'superseded'))
            )
        SQL);
        // ONE live flow per conversation; a second start supersedes by outcome.
        DB::statement('CREATE UNIQUE INDEX form_flow_checkpoints_live ON form_flow_checkpoints (tenant_id, conversation_id) WHERE outcome IS NULL');

        DB::statement('ALTER TABLE form_flow_checkpoints ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE form_flow_checkpoints FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON form_flow_checkpoints
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
        SQL);
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON form_flow_checkpoints FROM app');
        DB::statement('GRANT SELECT ON form_flow_checkpoints TO app');
        DB::statement('GRANT INSERT (id, tenant_id, conversation_id, form_id, version_id, step_index, answers, language, mode) ON form_flow_checkpoints TO app');
        DB::statement('GRANT UPDATE (step_index, answers, outcome, finished_at, updated_at) ON form_flow_checkpoints TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS form_flow_checkpoints');
    }
};
