<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\Copilot\TicketSummaryEmbedder;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 T-05 (S09 step 10): the NIGHTLY resolved-ticket summary embedding
 * behind FR-14's similar-tickets section. Per tenant, within tenant
 * context (the TelegramWindowSweep shape), bounded per run.
 */
final class AiEmbedTicketSummaries extends Command
{
    use RunsWithinTenant;

    protected $signature = 'ai:embed-ticket-summaries {--limit=200 : tickets per tenant per run}';

    protected $description = 'Summarize + embed resolved tickets for Copilot similar-tickets retrieval (Doc 09 T-05)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        /** @var list<object{tenant_id: string}> $tenants */
        $tenants = DB::select("SELECT DISTINCT tenant_id FROM tickets WHERE status IN ('resolved', 'closed')");

        $totals = ['embedded' => 0, 'skipped' => 0];
        foreach ($tenants as $row) {
            /** @var array{embedded: int, skipped: int} $counts */
            $counts = $this->withinTenant($row->tenant_id, fn (): array => app(TicketSummaryEmbedder::class)->run($limit));
            $totals['embedded'] += $counts['embedded'];
            $totals['skipped'] += $counts['skipped'];
        }

        $this->info(sprintf('ai:embed-ticket-summaries: %d embedded, %d skipped (T-05 nightly)', $totals['embedded'], $totals['skipped']));

        return self::SUCCESS;
    }
}
