<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_review_comments (SCHEMA AMENDMENT 4 item 6, countersigned; Doc 10 FR-07, §8 review).
 * created_at is ORDERING-RELEVANT (the thread renders in order) → clock_timestamp()
 * default, app INSERT/UPDATE denied (R-19a). A comment's text never changes after
 * posting; only the resolved pair mutates (DB-stamped by the resolving UPDATE).
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'variant_id', 'version_id', 'block_anchor', 'author_id', 'body', 'updated_at'];

    private const APP_UPDATE_COLUMNS = ['resolved_at', 'resolved_by', 'updated_at'];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_review_comments (
                id           uuid        NOT NULL PRIMARY KEY,
                tenant_id    uuid        NOT NULL REFERENCES tenants (id),
                variant_id   uuid        NOT NULL REFERENCES kb_article_variants (id),
                version_id   uuid,
                block_anchor text,
                author_id    uuid        NOT NULL,
                body         text        NOT NULL,
                resolved_at  timestamptz,
                resolved_by  uuid,
                created_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at   timestamptz NOT NULL DEFAULT clock_timestamp()
            )
            SQL);

        DB::statement('CREATE INDEX kb_review_comments_variant_index ON kb_review_comments (tenant_id, variant_id, created_at)');

        DB::statement('ALTER TABLE kb_review_comments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_review_comments FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_review_comments
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_review_comments FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_review_comments TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_review_comments TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_review_comments');
    }
};
