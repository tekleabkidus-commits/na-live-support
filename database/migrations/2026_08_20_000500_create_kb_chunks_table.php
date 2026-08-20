<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_chunks (SCHEMA AMENDMENT 3 item 4, countersigned; Doc 09 T-05, T-03's
 * index-time stripping, FR-10; [D10] fixture-fed until Doc 10 produces).
 *
 * - article_ref carries NO FK (the MembershipSource seam precedent): Doc 10
 *   owns articles; the chunk store is Doc 09's retrieval infrastructure.
 * - content is INSTRUCTION-STRIPPED AT INDEX TIME (T-03's sanitizer lives in
 *   the indexer); provenance records what was stripped and from where.
 * - content_tsv uses the 'simple' regconfig deliberately: Postgres ships no
 *   Amharic configuration, and a language-specific stemmer applied to the
 *   wrong language corrupts rank — the language COLUMN is the filter (T-05),
 *   'simple' tokenization is the honest keyword half for both launch
 *   languages.
 * - embedding vector(1024) = ADR 0018's pinned bge-m3 dims; nullable so a
 *   chunk can exist awaiting its embedding; HNSW cosine index (ADR 0015).
 * - retrievable FLIPS on unpublish/tombstone (EC-06) — never a delete
 *   (nothing hard-deletes at tenant scope); REVOKE DELETE enforces.
 * - ai_usable ON THE CHUNK is the amendment's flag 3 (countersigned): the
 *   category model is Doc 10's; this flag is the honest interim, re-derived
 *   when Doc 10's categories land ([D10] reopener).
 * - UNIQUE (tenant, article_ref, chunk_index) is the re-index upsert arbiter
 *   and the only non-PK unique (the arbiter law).
 *
 * APP_WRITABLE_COLUMNS: INSERT everything except created_at (R-19b posture;
 * content_tsv is GENERATED and unnameable); UPDATE reaches the re-index set
 * (content, provenance, embedding, language, audience, ai_usable,
 * retrievable, updated_at) — identity columns never move by app.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_chunks (
                id           uuid        NOT NULL PRIMARY KEY,
                tenant_id    uuid        NOT NULL REFERENCES tenants (id),
                article_ref  uuid        NOT NULL,
                chunk_index  integer     NOT NULL,
                language     text        NOT NULL,
                audience     text        NOT NULL DEFAULT 'public',
                ai_usable    boolean     NOT NULL DEFAULT false,
                content      text        NOT NULL,
                provenance   jsonb       NOT NULL,
                content_tsv  tsvector    GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED,
                embedding    vector(1024),
                retrievable  boolean     NOT NULL DEFAULT true,
                created_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at   timestamptz,
                CONSTRAINT kb_chunks_audience_check CHECK (audience IN ('public', 'internal'))
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX kb_chunks_identity ON kb_chunks (tenant_id, article_ref, chunk_index)');
        DB::statement('CREATE INDEX kb_chunks_tsv_index ON kb_chunks USING gin (content_tsv)');
        DB::statement('CREATE INDEX kb_chunks_embedding_index ON kb_chunks USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX kb_chunks_retrieval_index ON kb_chunks (tenant_id, language, audience) WHERE retrievable AND ai_usable');

        DB::statement('ALTER TABLE kb_chunks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kb_chunks FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON kb_chunks
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON kb_chunks FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, article_ref, chunk_index, language, audience, ai_usable, content, provenance, embedding, retrievable, updated_at) ON kb_chunks TO app');
        DB::statement('GRANT UPDATE (content, provenance, embedding, language, audience, ai_usable, retrievable, updated_at) ON kb_chunks TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunks');
    }
};
