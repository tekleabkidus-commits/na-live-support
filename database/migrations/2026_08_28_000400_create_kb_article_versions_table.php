<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_article_versions (SCHEMA AMENDMENT 4 item 4, countersigned; Doc 10 FR-08, AC-07,
 * SEC-04, BR-06) — IMMUTABLE BY GRANT: the app role holds NO UPDATE grant at all and no
 * DELETE; a version is written once (full snapshot, content_hash for T-02 determinism,
 * sanitizer flags + the override decision ON the version). published_at is
 * ORDERING-RELEVANT (SS-04 forensics) → clock_timestamp() default, app INSERT-denied
 * (R-19a/R-19b). Restore = a NEW DRAFT from an old version, never a rewrite here.
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'variant_id', 'article_id', 'version', 'language', 'title', 'slug', 'summary', 'content', 'content_hash', 'audience_at_publish', 'author_id', 'note', 'sanitizer_flags', 'sanitizer_override'];

    private const APP_UPDATE_COLUMNS = [];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_article_versions (
                id                  uuid        NOT NULL PRIMARY KEY,
                tenant_id           uuid        NOT NULL REFERENCES tenants (id),
                variant_id          uuid        NOT NULL REFERENCES kb_article_variants (id),
                article_id          uuid        NOT NULL,
                version             integer     NOT NULL,
                language            text        NOT NULL,
                title               text        NOT NULL,
                slug                text        NOT NULL,
                summary             text        NOT NULL,
                content             jsonb       NOT NULL,
                content_hash        text        NOT NULL,
                audience_at_publish text        NOT NULL,
                author_id           uuid        NOT NULL,
                note                text,
                sanitizer_flags     jsonb       NOT NULL DEFAULT '[]'::jsonb,
                sanitizer_override  jsonb,
                published_at        timestamptz NOT NULL DEFAULT clock_timestamp(),
                created_at          timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT kb_article_versions_audience_check CHECK (audience_at_publish IN ('public', 'internal'))
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX kb_article_versions_variant_version_unique ON kb_article_versions (tenant_id, variant_id, version)');

        DB::statement('ALTER TABLE kb_article_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_article_versions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_article_versions
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_article_versions FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_article_versions TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_article_versions TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_article_versions');
    }
};
