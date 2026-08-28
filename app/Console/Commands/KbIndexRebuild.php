<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 10 FS-04 / Doc 09 FS-02 (the pgvector rebuild note — [D10] closing row 7): the
 * embeddings are STORED on the rows, not only indexed, so a corrupted HNSW index is
 * rebuilt FROM THE ROWS — `REINDEX INDEX CONCURRENTLY` on each partition's vector index
 * (and its GIN twin). Index DDL is MIGRATOR-OWNED (ADR 0003 / ADR 0015: the migrator
 * creates every index and is its owner; neither `app` nor `platform_admin` may reindex —
 * the proof asserts the app refusal), so this ops command runs on the migrator connection,
 * an operator-invoked DDL run, never application traffic. Concurrent rebuild keeps reads
 * open; Doc 09's posture during the window is its no_grounding handoff (ChatbotTurn's
 * retrieval-failure path). Alert + runbook pointer logged either way.
 */
final class KbIndexRebuild extends Command
{
    protected $signature = 'kb:index-rebuild';

    protected $description = 'Rebuild the kb_chunks HNSW + GIN indexes from the rows under the platform role (Doc 10 FS-04)';

    public function handle(): int
    {
        $rebuilt = [];
        /** @var list<object{indexname: string}> $rows */
        $rows = DB::connection('pgsql_migrator')->select("SELECT i.relname AS indexname FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_am am ON am.oid = i.relam WHERE t.relname IN ('kb_chunks_public', 'kb_chunks_internal') AND am.amname IN ('hnsw', 'gin') ORDER BY 1");
        foreach ($rows as $row) {
            DB::connection('pgsql_migrator')->statement(sprintf('REINDEX INDEX CONCURRENTLY %s', $row->indexname));
            $rebuilt[] = $row->indexname;
        }
        Log::warning('kb.index.rebuilt', ['indexes' => $rebuilt, 'runbook' => 'docs/runbooks/ai-injection-sev2.md (retrieval posture) / Doc 10 FS-04']);
        $this->info(sprintf('kb:index-rebuild: %d index(es) rebuilt from rows: %s', count($rebuilt), implode(', ', $rebuilt)));

        return self::SUCCESS;
    }
}
