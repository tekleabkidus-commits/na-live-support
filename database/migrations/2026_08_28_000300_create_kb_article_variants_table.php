<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_article_variants (SCHEMA AMENDMENT 4 item 3, countersigned; Doc 10 FR-02/03/05/07,
 * FR-13/NFR-01 states, T-07, EC-12, §14). Workflow status is VARIANT-level; translation
 * state per variant; working_copy = FR-05's autosave/EC-12 recovery point (never history);
 * index_status carries the honest "index pending" split; retrieval_generation = the
 * generation the ONE-statement swap made current. Per-language slugs (§14; EC-06).
 * current_version_id is a SOFT reference (no FK): versions reference variants, so an FK
 * back would be a cycle — the publish door is its only writer (disclosed deviation from
 * the amendment's silence, not from its text). Provenance stamps (working_copy_saved_at,
 * submitted_at, published_at) are written IN SQL by their doors' clock_timestamp().
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'article_id', 'language', 'status', 'translation_state', 'translation_source', 'title', 'slug', 'summary', 'working_copy', 'index_status', 'retrieval_generation', 'updated_at'];

    private const APP_UPDATE_COLUMNS = ['status', 'translation_state', 'translation_source', 'title', 'slug', 'summary', 'working_copy', 'working_copy_saved_at', 'current_version_id', 'index_status', 'retrieval_generation', 'submitted_at', 'published_at', 'updated_at'];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_article_variants (
                id                    uuid        NOT NULL PRIMARY KEY,
                tenant_id             uuid        NOT NULL REFERENCES tenants (id),
                article_id            uuid        NOT NULL REFERENCES kb_articles (id),
                language              text        NOT NULL,
                status                text        NOT NULL DEFAULT 'draft',
                translation_state     text        NOT NULL DEFAULT 'missing',
                translation_source    text        NOT NULL DEFAULT 'human',
                title                 text        NOT NULL DEFAULT '',
                slug                  text        NOT NULL,
                summary               text        NOT NULL DEFAULT '',
                working_copy          jsonb       NOT NULL DEFAULT '{}'::jsonb,
                working_copy_saved_at timestamptz,
                current_version_id    uuid,
                index_status          text        NOT NULL DEFAULT 'none',
                retrieval_generation  integer     NOT NULL DEFAULT 0,
                submitted_at          timestamptz,
                published_at          timestamptz,
                created_at            timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at            timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT kb_article_variants_status_check CHECK (status IN ('draft', 'in_review', 'published', 'archived')),
                CONSTRAINT kb_article_variants_translation_state_check CHECK (translation_state IN ('current', 'outdated', 'missing')),
                CONSTRAINT kb_article_variants_translation_source_check CHECK (translation_source IN ('human', 'ai')),
                CONSTRAINT kb_article_variants_index_status_check CHECK (index_status IN ('none', 'pending', 'current', 'failed'))
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX kb_article_variants_article_language_unique ON kb_article_variants (tenant_id, article_id, language)');
        DB::statement('CREATE UNIQUE INDEX kb_article_variants_slug_unique ON kb_article_variants (tenant_id, language, slug)');

        DB::statement('ALTER TABLE kb_article_variants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_article_variants FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_article_variants
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_article_variants FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_article_variants TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_article_variants TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_article_variants');
    }
};
