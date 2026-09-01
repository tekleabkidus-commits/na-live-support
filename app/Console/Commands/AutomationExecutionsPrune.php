<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-06 — the 90-day execution-log retention. The app role holds NO
 * DELETE on the table (INSERT-only + RIDER 2's one UPDATE), so retention runs
 * on the platform role like every pruner (the kb:chunks-prune posture).
 */
final class AutomationExecutionsPrune extends Command
{
    protected $signature = 'automation:executions-prune {--days=90}';

    protected $description = 'Prune automation execution logs past retention (Doc 12 FR-06, 90 d)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 12 FR-06: execution-log retention prune',
            static fn (): int => DB::connection(PlatformConnection::NAME)
                ->delete('DELETE FROM automation_rule_executions WHERE executed_at < clock_timestamp() - make_interval(days => ?)', [$days]),
        );
        $this->info(sprintf('pruned %d execution row(s) past %d days', $deleted, $days));

        return self::SUCCESS;
    }
}
