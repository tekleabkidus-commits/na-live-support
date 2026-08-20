<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_ticket_summaries (SCHEMA AMENDMENT 3 item 5, countersigned; Doc 09
 * T-05's similar-tickets half) — one living summary per resolved ticket,
 * nightly re-embedded; summaries fixture-fed until the summarize job lands.
 *
 * Doc 03 scope filtering happens AT QUERY TIME by joining ticket visibility
 * (T-05's own words) — NO scope columns are stored: a stored scope goes
 * stale on reassignment (the S08 CustomerSearchIndexer lesson).
 *
 * UNIQUE (tenant, ticket) is the nightly upsert arbiter and the only non-PK
 * unique. embedded_at is maintenance metadata (job stamp), not an ordering
 * key. APP_WRITABLE_COLUMNS: INSERT everything except created_at; UPDATE
 * reaches (summary, language, embedding, embedded_at, updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE ai_ticket_summaries (
                id          uuid        NOT NULL PRIMARY KEY,
                tenant_id   uuid        NOT NULL REFERENCES tenants (id),
                ticket_id   uuid        NOT NULL REFERENCES tickets (id),
                summary     text        NOT NULL,
                language    text        NOT NULL,
                embedding   vector(1024),
                embedded_at timestamptz,
                created_at  timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at  timestamptz
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX ai_ticket_summaries_identity ON ai_ticket_summaries (tenant_id, ticket_id)');
        DB::statement('CREATE INDEX ai_ticket_summaries_embedding_index ON ai_ticket_summaries USING hnsw (embedding vector_cosine_ops)');

        DB::statement('ALTER TABLE ai_ticket_summaries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_ticket_summaries FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ai_ticket_summaries
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ai_ticket_summaries FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, ticket_id, summary, language, embedding, embedded_at, updated_at) ON ai_ticket_summaries TO app');
        DB::statement('GRANT UPDATE (summary, language, embedding, embedded_at, updated_at) ON ai_ticket_summaries TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_ticket_summaries');
    }
};
