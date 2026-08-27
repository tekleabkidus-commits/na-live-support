<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_categories (SCHEMA AMENDMENT 4 item 1, countersigned; Doc 10 FR-01, EC-03, A-43).
 * Single tree, depth ≤ 3 by CHECK on the materialized depth; name per language
 * (jsonb); ai_usable is the CATEGORY-level flag that re-derives Doc 09's chunk
 * interim (amendment-3 FLAG 3's reopener); soft delete (A-43). position is the
 * ordering key (an int, never a clock). APP_WRITABLE_COLUMNS: INSERT minus
 * created_at (R-19b); UPDATE = the mutable set incl. deleted_at; REVOKE DELETE.
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'parent_id', 'depth', 'name', 'slug', 'description', 'position', 'icon', 'ai_usable', 'visibility_default', 'updated_at'];

    private const APP_UPDATE_COLUMNS = ['parent_id', 'depth', 'name', 'slug', 'description', 'position', 'icon', 'ai_usable', 'visibility_default', 'deleted_at', 'updated_at'];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_categories (
                id                 uuid        NOT NULL PRIMARY KEY,
                tenant_id          uuid        NOT NULL REFERENCES tenants (id),
                parent_id          uuid        REFERENCES kb_categories (id),
                depth              smallint    NOT NULL,
                name               jsonb       NOT NULL,
                slug               text        NOT NULL,
                description        jsonb       NOT NULL DEFAULT '{}'::jsonb,
                position           integer     NOT NULL DEFAULT 0,
                icon               text,
                ai_usable          boolean     NOT NULL DEFAULT false,
                visibility_default text        NOT NULL DEFAULT 'public',
                deleted_at         timestamptz,
                created_at         timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at         timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT kb_categories_depth_check CHECK (depth BETWEEN 1 AND 3),
                CONSTRAINT kb_categories_visibility_default_check CHECK (visibility_default IN ('public', 'internal'))
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX kb_categories_slug_unique ON kb_categories (tenant_id, parent_id, slug) NULLS NOT DISTINCT');
        DB::statement('CREATE INDEX kb_categories_parent_index ON kb_categories (tenant_id, parent_id, position)');

        DB::statement('ALTER TABLE kb_categories ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_categories FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_categories
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_categories FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_categories TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_categories TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_categories');
    }
};
