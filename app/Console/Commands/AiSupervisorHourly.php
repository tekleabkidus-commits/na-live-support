<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\Supervisor\AnomalyScan;
use App\Domain\Ai\Supervisor\SentimentWatch;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Doc 09 FR-17 (S09 step 11): the hourly supervisor pass — anomaly scan + sentiment watch, per tenant, within tenant context. */
final class AiSupervisorHourly extends Command
{
    use RunsWithinTenant;

    protected $signature = 'ai:supervisor-hourly';

    protected $description = 'Doc 09 FR-17 hourly: anomaly_scan deltas vs baseline + live sentiment watch (per tenant)';

    public function handle(): int
    {
        /** @var list<object{tenant_id: string}> $tenants */
        $tenants = DB::select("SELECT DISTINCT tenant_id FROM ai_decisions WHERE task = 'classify_message' AND created_at >= clock_timestamp() - interval '8 days'");
        $alerts = 0;
        $items = 0;
        $paused = 0;
        foreach ($tenants as $row) {
            /** @var array{alerts: int, items: int, paused: int} $counts */
            $counts = $this->withinTenant($row->tenant_id, function (): array {
                $scan = app(AnomalyScan::class)->run();
                $watch = app(SentimentWatch::class)->run();

                return ['alerts' => count($scan['alerts']), 'items' => count($watch['items']), 'paused' => $scan['paused'] !== null ? 1 : 0];
            });
            $alerts += $counts['alerts'];
            $items += $counts['items'];
            $paused += $counts['paused'];
        }
        $this->info(sprintf('ai:supervisor-hourly: %d alert(s), %d sentiment item(s), %d tenant(s) paused (FS-01)', $alerts, $items, $paused));

        return self::SUCCESS;
    }
}
