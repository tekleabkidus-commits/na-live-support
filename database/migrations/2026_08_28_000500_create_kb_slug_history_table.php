<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_slug_history (SCHEMA AMENDMENT 4 item 5, countersigned; Doc 10 T-06, EC-06, FR-16)
 * — APPEND-ONLY: a slug ever used resolves forever to the variant that owned it; the
 * app role INSERTs only (no UPDATE grant, no DELETE). A new owner of a retired slug is
 * refused by the unique index — EC-06's auto-suffix keeps both URLs working.
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = ['id', 'tenant_id', 'article_id', 'variant_id', 'language', 'slug'];

    private const APP_UPDATE_COLUMNS = [];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_slug_history (
                id         uuid        NOT NULL PRIMARY KEY,
                tenant_id  uuid        NOT NULL REFERENCES tenants (id),
                article_id uuid        NOT NULL,
                variant_id uuid        NOT NULL REFERENCES kb_article_variants (id),
                language   text        NOT NULL,
                slug       text        NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX kb_slug_history_slug_unique ON kb_slug_history (tenant_id, language, slug)');

        DB::statement('ALTER TABLE kb_slug_history ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_slug_history FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_slug_history
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_slug_history FROM app');
        DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).') ON kb_slug_history TO app');
        if (self::APP_UPDATE_COLUMNS !== []) {
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).') ON kb_slug_history TO app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_slug_history');
    }
};
