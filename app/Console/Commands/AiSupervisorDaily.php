<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\Supervisor\DailyDigest;
use App\Domain\Ai\Supervisor\QaPrescore;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Doc 09 FR-17 (S09 step 11): the daily supervisor pass — QA pre-score sample + the localized digest per manager, per tenant. */
final class AiSupervisorDaily extends Command
{
    use RunsWithinTenant;

    protected $signature = 'ai:supervisor-daily';

    protected $description = 'Doc 09 FR-17 daily: qa_prescore (sampled, advisory) + daily_digest (6 bullets, per manager scope, localized)';

    public function handle(): int
    {
        /** @var list<object{id: string}> $tenants */
        $tenants = DB::select("SELECT id FROM tenants WHERE status = 'active'");
        $scored = 0;
        $digests = 0;
        foreach ($tenants as $row) {
            /** @var array{scored: int, digests: int} $counts */
            $counts = $this->withinTenant($row->id, function (): array {
                $qa = app(QaPrescore::class)->run();
                $digest = app(DailyDigest::class)->run();

                return ['scored' => $qa['scored'], 'digests' => count($digest['digests'])];
            });
            $scored += $counts['scored'];
            $digests += $counts['digests'];
        }
        $this->info(sprintf('ai:supervisor-daily: %d ticket(s) pre-scored, %d digest(s) written', $scored, $digests));

        return self::SUCCESS;
    }
}
