<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_feedback (SCHEMA AMENDMENT 4 item 7, countersigned; Doc 10 FR-11/12, SEC-05, EC-10,
 * AC-08). created_at is METRIC-RELEVANT (AC-08 windows, EC-10 anomaly rate) → app
 * INSERT/UPDATE denied. FLAG B (countersigned, the standing law attached): the partial
 * unique (tenant, variant_id, visitor_hash) is EC-10's dedupe arbiter and the ONLY non-PK
 * unique on this table FOREVER — it IS an ON CONFLICT target (latest wins); KbGrantPhysicsTest
 * asserts no second unique index and no other ON CONFLICT targets the table.
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'article_id', 'variant_id', 'source', 'visitor_hash', 'staff_id', 'score', 'comment', 'updated_at'];

    private const APP_UPDATE_COLUMNS = ['score', 'comment', 'actioned_at', 'actioned_by', 'updated_at'];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_feedback (
                id           uuid        NOT NULL PRIMARY KEY,
                tenant_id    uuid        NOT NULL REFERENCES tenants (id),
                article_id   uuid        NOT NULL,
                variant_id   uuid        NOT NULL REFERENCES kb_article_variants (id),
                source       text        NOT NULL,
                visitor_hash text,
                staff_id     uuid,
                score        smallint    NOT NULL,
                comment      text,
                actioned_at  timestamptz,
                actioned_by  uuid,
                created_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT kb_feedback_source_check CHECK (source IN ('widget', 'telegram', 'staff')),
                CONSTRAINT kb_feedback_score_check CHECK (score IN (-1, 1)),
                CONSTRAINT kb_feedback_author_check CHECK ((visitor_hash IS NULL) <> (staff_id IS NULL))
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX kb_feedback_visitor_dedupe ON kb_feedback (tenant_id, variant_id, visitor_hash) WHERE visitor_hash IS NOT NULL');
        DB::statement('CREATE INDEX kb_feedback_variant_index ON kb_feedback (tenant_id, variant_id, created_at)');

        DB::statement('ALTER TABLE kb_feedback ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_feedback FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_feedback
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_feedback FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_feedback TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_feedback TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_feedback');
    }
};
