<?php

declare(strict_types=1);

namespace App\Console\Commands\Routing;

use App\Domain\Routing\Capacity\AssignmentStats;
use App\Domain\Routing\Pipeline\PositionInQueue;
use App\Domain\Routing\Waiting\WaitingSets;
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

    public function handle(AssignmentStats $stats, WaitingSets $waiting, PositionInQueue $position, \App\Domain\Routing\Temporary\ExpiryZset $expiry): int
    {
        $n = $stats->rebuild();
        $this->info(sprintf('routing:rebuild — %d agent counter(s) recomputed from tickets', $n));
        // FS-01: the waiting sets REPLACED from tickets with the engine's own score; RR pointers
        // read their DB checkpoint on the next order() (a fairness restart, noted here).
        $now = $position->dbNow();   // ONE clock for the whole pass (NFR-03: a tick between two same-second tickets must not reorder them)
        $m = $waiting->rebuild(function (object $row) use ($position, $now): int {
            $pos = $position->compute($row->queue_id, ['id' => $row->id, 'priority' => $row->priority, 'reopened_count' => (int) $row->reopened_count, 'customer_id' => $row->customer_id, 'queued_at' => $row->queued_at, 'tags' => []], $now);
            $queuedAt = is_string($row->queued_at) ? (int) strtotime($row->queued_at) : time();

            return WaitingSets::score($pos['starved'], $pos['score'], $queuedAt);
        });
        $this->info(sprintf('routing:rebuild — %d waiting member(s) restored from tickets; RR pointers fall back to their DB checkpoints (fairness restart noted)', $m));

        // T-07/FS-01 (step 7): the expiry ZSET REPLACED from the live temporary rows (one member per override).
        $k = $expiry->rebuild();
        $this->info(sprintf('routing:rebuild — %d temporary-membership expiry member(s) restored', $k));

        return self::SUCCESS;
    }
}
