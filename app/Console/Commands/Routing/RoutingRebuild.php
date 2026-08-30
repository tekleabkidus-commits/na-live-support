<?php

declare(strict_types=1);

namespace App\Console\Commands\Routing;

use App\Domain\Routing\Capacity\AssignmentStats;
use Illuminate\Console\Command;

/**
 * Doc 11 FS-01 — rebuild routing state from DB truth. Step 4: the assignment counters
 * (agent_assignment_stats from tickets). Step 6 adds the waiting ZSETs and RR pointers.
 * Runs for the BOUND tenant (the consistency-command pattern: capture-and-restore the
 * caller's binding is the caller's job — tests bind, ops binds via --tenant at step 6).
 */
final class RoutingRebuild extends Command
{
    protected $signature = 'routing:rebuild';

    protected $description = 'Doc 11 FS-01: rebuild agent_assignment_stats (and, from step 6, the waiting ZSETs) from database truth for the bound tenant';

    public function handle(AssignmentStats $stats): int
    {
        $n = $stats->rebuild();
        $this->info(sprintf('routing:rebuild — %d agent counter(s) recomputed from tickets', $n));

        return self::SUCCESS;
    }
}
