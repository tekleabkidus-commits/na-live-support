<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 10 T-01's "delete old async" — STOP-A as ruled (SCHEMA AMENDMENT 4): the app
 * role NEVER deletes kb_chunks (REVOKE DELETE on the parent and both partitions); this
 * PLATFORM-ROLE job (the Doc 01 SEC-02 retention class — its third sanctioned use,
 * scoped by the amendment's filing, not a widening) prunes rows that are NOT retrievable,
 * belong to a generation older than the variant's current generation minus one, and are
 * not the variant's current version. Forensics never read chunks (SS-04 resolves through
 * kb_article_versions), so a pruned row loses nothing. Cross-tenant BY DESIGN (a
 * retention sweep visits every tenant) via the platform connection.
 */
final class KbChunksPrune extends Command
{
    protected $signature = 'kb:chunks-prune';

    protected $description = 'Prune superseded, non-retrievable kb_chunks generations under the platform role (Doc 10 T-01; SCHEMA AMENDMENT 4)';

    public function handle(): int
    {
        $pruned = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 10 T-01 superseded-generation prune (SCHEMA AMENDMENT 4; app holds no DELETE)',
            fn (): int => DB::connection('pgsql_platform')->delete(
                <<<'SQL'
            DELETE FROM kb_chunks c
             USING kb_article_variants v
             WHERE v.tenant_id = c.tenant_id
               AND v.id = c.variant_id
               AND NOT c.retrievable
               AND c.retrieval_generation < v.retrieval_generation - 1
               AND c.version_id IS DISTINCT FROM v.current_version_id
            SQL,
            ),
        );

        Log::info('kb.chunks.pruned', ['pruned_count' => $pruned]);
        $this->info(sprintf('kb:chunks-prune: %d superseded chunk row(s) removed under the platform role.', $pruned));

        return self::SUCCESS;
    }
}
