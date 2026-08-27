<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * kb_chunks — SCHEMA AMENDMENT 4 item 8, SWAP phase: the legacy single table steps
 * aside as `kb_chunks_legacy` (its indexes/constraints renamed out of the way) and the
 * partitioned `kb_chunks_v2` takes the canonical name with canonical index names.
 * Nothing is dropped here (the CONTRACT phase is its own migration — FLAG C).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kb_chunks RENAME TO kb_chunks_legacy');
        DB::statement('ALTER TABLE kb_chunks_legacy RENAME CONSTRAINT kb_chunks_pkey TO kb_chunks_legacy_pkey');
        DB::statement('ALTER TABLE kb_chunks_legacy RENAME CONSTRAINT kb_chunks_audience_check TO kb_chunks_legacy_audience_check');
        foreach (['identity', 'tsv_index', 'embedding_index', 'retrieval_index'] as $suffix) {
            DB::statement("ALTER INDEX kb_chunks_{$suffix} RENAME TO kb_chunks_legacy_{$suffix}");
        }

        DB::statement('ALTER TABLE kb_chunks_v2 RENAME TO kb_chunks');
        DB::statement('ALTER TABLE kb_chunks RENAME CONSTRAINT kb_chunks_v2_pkey TO kb_chunks_pkey');
        DB::statement('ALTER TABLE kb_chunks RENAME CONSTRAINT kb_chunks_v2_audience_check TO kb_chunks_audience_check');
        foreach (['arbiter', 'article_index', 'tsv_index', 'embedding_index', 'retrieval_index'] as $suffix) {
            DB::statement("ALTER INDEX kb_chunks_v2_{$suffix} RENAME TO kb_chunks_{$suffix}");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE kb_chunks RENAME TO kb_chunks_v2');
        DB::statement('ALTER TABLE kb_chunks_legacy RENAME TO kb_chunks');
    }
};
