<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kb_chunks — SCHEMA AMENDMENT 4 item 8, EXPAND phase (countersigned; STOP-A + STOP-B
 * rulings). The Doc 09 chunk store is RECREATED as `kb_chunks_v2`, LIST-PARTITIONED BY
 * audience — kb_chunks_public / kb_chunks_internal, each with its OWN HNSW + GIN
 * (index-level separation, FR-15/SEC-01: the chatbot path reads the public partition BY
 * TABLE NAME, step 7) — with the T-01/FR-14 columns (variant_id, version_id,
 * retrieval_generation, heading_path, sanitizer_flags) and the NEW arbiter
 * UNIQUE (tenant_id, audience, variant_id, retrieval_generation, chunk_index) — the only
 * non-PK unique; the old (tenant, article_ref, chunk_index) arbiter is gone with it
 * (STOP-A condition 3; the FLAG-2 sweep in KbGrantPhysicsTest proves no ON CONFLICT
 * ever targeted it). Partition-key law: PK (id, audience). Existing rows (fixture rows
 * only — no seeder writes kb_chunks, verified) copy over per tenant with
 * variant_id := article_ref, version_id := article_ref, retrieval_generation := 1.
 * Tenant enumeration rides the platform role under A-41's fence (migration-local
 * enumeration ONLY; every write runs under the tenant's own RLS binding).
 *
 * GRANTS (parent + both partitions; STOP-A condition 5): INSERT minus created_at;
 * UPDATE = (content, provenance, embedding, language, ai_usable, retrievable,
 * heading_path, sanitizer_flags, version_id, retrieval_generation, updated_at) —
 * audience and variant_id are NOT updatable (an audience change is a re-index into the
 * other partition through the one door, never a row move); REVOKE DELETE everywhere —
 * the platform-role prune (kb:chunks-prune) is the only pen that deletes.
 */
return new class extends Migration
{
    private const APP_INSERT_COLUMNS = [
        'id', 'tenant_id', 'article_ref', 'variant_id', 'version_id', 'retrieval_generation', 'chunk_index',
        'language', 'audience', 'ai_usable', 'heading_path', 'content', 'provenance', 'sanitizer_flags',
        'embedding', 'retrievable', 'updated_at',
    ];

    private const APP_UPDATE_COLUMNS = [
        'content', 'provenance', 'embedding', 'language', 'ai_usable', 'retrievable',
        'heading_path', 'sanitizer_flags', 'version_id', 'retrieval_generation', 'updated_at',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE kb_chunks_v2 (
                id                   uuid        NOT NULL,
                tenant_id            uuid        NOT NULL REFERENCES tenants (id),
                article_ref          uuid        NOT NULL,
                variant_id           uuid        NOT NULL,
                version_id           uuid        NOT NULL,
                retrieval_generation integer     NOT NULL,
                chunk_index          integer     NOT NULL,
                language             text        NOT NULL,
                audience             text        NOT NULL DEFAULT 'public',
                ai_usable            boolean     NOT NULL DEFAULT false,
                heading_path         text        NOT NULL DEFAULT '',
                content              text        NOT NULL,
                provenance           jsonb       NOT NULL,
                sanitizer_flags      jsonb       NOT NULL DEFAULT '[]'::jsonb,
                content_tsv          tsvector    GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED,
                embedding            vector(1024),
                retrievable          boolean     NOT NULL DEFAULT true,
                created_at           timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at           timestamptz,
                CONSTRAINT kb_chunks_v2_pkey PRIMARY KEY (id, audience),
                CONSTRAINT kb_chunks_v2_audience_check CHECK (audience IN ('public', 'internal'))
            ) PARTITION BY LIST (audience)
            SQL);
        DB::statement("CREATE TABLE kb_chunks_public PARTITION OF kb_chunks_v2 FOR VALUES IN ('public')");
        DB::statement("CREATE TABLE kb_chunks_internal PARTITION OF kb_chunks_v2 FOR VALUES IN ('internal')");

        // Parent-declared indexes materialize PER PARTITION (own HNSW + GIN each).
        DB::statement('CREATE UNIQUE INDEX kb_chunks_v2_arbiter ON kb_chunks_v2 (tenant_id, audience, variant_id, retrieval_generation, chunk_index)');
        DB::statement('CREATE INDEX kb_chunks_v2_article_index ON kb_chunks_v2 (tenant_id, article_ref)');
        DB::statement('CREATE INDEX kb_chunks_v2_tsv_index ON kb_chunks_v2 USING gin (content_tsv)');
        DB::statement('CREATE INDEX kb_chunks_v2_embedding_index ON kb_chunks_v2 USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX kb_chunks_v2_retrieval_index ON kb_chunks_v2 (tenant_id, language, audience) WHERE retrievable AND ai_usable');

        foreach (['kb_chunks_v2', 'kb_chunks_public', 'kb_chunks_internal'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$table} FROM app");
            DB::statement('GRANT INSERT ('.implode(', ', self::APP_INSERT_COLUMNS).") ON {$table} TO app");
            DB::statement('GRANT UPDATE ('.implode(', ', self::APP_UPDATE_COLUMNS).") ON {$table} TO app");
        }

        // Copy the fixture rows per tenant (A-41's fence: migration-local tenant enumeration).
        /** @var list<object{id: string}> $tenants */
        $tenants = DB::connection('pgsql_platform')->select('SELECT id FROM tenants');
        foreach ($tenants as $tenant) {
            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$tenant->id]);
            DB::statement(<<<'SQL'
                INSERT INTO kb_chunks_v2 (id, tenant_id, article_ref, variant_id, version_id, retrieval_generation, chunk_index,
                                          language, audience, ai_usable, heading_path, content, provenance, sanitizer_flags,
                                          embedding, retrievable, created_at, updated_at)
                SELECT id, tenant_id, article_ref, article_ref, article_ref, 1, chunk_index,
                       language, audience, ai_usable, '', content, provenance, '[]'::jsonb,
                       embedding, retrievable, created_at, updated_at
                  FROM kb_chunks
                SQL);
        }
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunks_v2');
    }
};
