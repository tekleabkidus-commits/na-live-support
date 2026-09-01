<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Automation\Engine\ScheduleSweep;
use Illuminate\Console\Command;

/**
 * Doc 12 FR-01's schedule trigger — the 1-minute sweep. One pass per
 * invocation; the scheduler provides the cadence (the T-03 sweep posture).
 */
final class AutomationScheduleSweep extends Command
{
    protected $signature = 'automation:schedule-sweep';

    protected $description = 'Fire due schedule-triggered automation rules (Doc 12 FR-01/EC-10)';

    public function handle(ScheduleSweep $sweep): int
    {
        $result = $sweep->sweep();
        $this->info(sprintf('fired %d, re-armed %d', $result['fired'], $result['rearmed']));

        return self::SUCCESS;
    }
}
