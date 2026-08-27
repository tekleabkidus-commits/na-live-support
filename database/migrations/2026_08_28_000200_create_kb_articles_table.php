<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_articles (SCHEMA AMENDMENT 4 item 2, countersigned; Doc 10 FR-02, BR-03, FR-04/BR-07,
 * EC-11, A-43). audience is ARTICLE-level — one audience decision per article, BR-03 as
 * physics (no per-variant column exists to disagree). FLAG A (countersigned): audience is
 * app-updatable because EC-11 is a real operation; the ONE door (step 4) audits it
 * high-visibility and takes the full pipeline — sole-writer sweep + sabotage #12 prove it.
 * seeded + seeded_fingerprint carry BR-07's RoleSeeder contract. Soft delete (A-43).
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'category_id', 'audience', 'source_language', 'tags', 'related_article_ids', 'seeded', 'seeded_fingerprint', 'created_by', 'updated_at'];

    private const APP_UPDATE_COLUMNS = ['category_id', 'audience', 'source_language', 'tags', 'related_article_ids', 'seeded_fingerprint', 'deleted_at', 'updated_at'];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_articles (
                id                  uuid        NOT NULL PRIMARY KEY,
                tenant_id           uuid        NOT NULL REFERENCES tenants (id),
                category_id         uuid        NOT NULL REFERENCES kb_categories (id),
                audience            text        NOT NULL DEFAULT 'public',
                source_language     text        NOT NULL,
                tags                jsonb       NOT NULL DEFAULT '[]'::jsonb,
                related_article_ids jsonb       NOT NULL DEFAULT '[]'::jsonb,
                seeded              boolean     NOT NULL DEFAULT false,
                seeded_fingerprint  text,
                created_by          uuid        NOT NULL,
                deleted_at          timestamptz,
                created_at          timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at          timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT kb_articles_audience_check CHECK (audience IN ('public', 'internal'))
            )
            SQL);

        DB::statement('CREATE INDEX kb_articles_category_index ON kb_articles (tenant_id, category_id)');

        DB::statement('ALTER TABLE kb_articles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_articles FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_articles
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_articles FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_articles TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_articles TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
