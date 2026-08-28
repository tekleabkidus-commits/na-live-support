<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FS-04 / Doc 09 FS-02's rebuild note — [D10] closing row 7 (S10 step 7): the
 * pgvector + GIN indexes rebuild FROM THE ROWS under the platform role; every rebuilt
 * index is valid afterwards; the app role cannot run the DDL (index rebuilds are never
 * the app's).
 */
it('kb:index-rebuild reindexes both partitions\' hnsw + gin indexes concurrently under the platform role and leaves every index valid; the app role is refused the DDL', function (): void {
    bindTenant(createTenant());
    try {
        DB::statement('REINDEX INDEX kb_chunks_public_embedding_idx');
        $this->fail('the app role reindexed');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toMatch('/permission denied|does not exist|must be owner/');
    }
    app(TenantContext::class)->clear();

    Artisan::call('kb:index-rebuild');
    $out = Artisan::output();
    expect($out)->toContain('index(es) rebuilt from rows');
    preg_match('/(\d+) index\(es\)/', $out, $m);
    expect((int) ($m[1] ?? 0))->toBe(4, 'two partitions × (hnsw + gin)');

    $invalid = DB::connection('pgsql_platform')->select("SELECT i.relname FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid JOIN pg_class t ON t.oid = ix.indrelid WHERE t.relname IN ('kb_chunks_public', 'kb_chunks_internal') AND NOT ix.indisvalid");
    expect($invalid)->toBe([]);
});
