<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sla\Timers\SlaTimerSweep;
use Illuminate\Console\Command;

/**
 * Doc 12 FR-10/NFR-02 — the 1-minute SLA sweep (the T-03 posture); --rebuild
 * is FS-02's recovery arm (late-not-early, flagged late_fired).
 */
final class SlaTimersSweep extends Command
{
    protected $signature = 'sla:timers-sweep {--limit=200 : due candidates per pass} {--rebuild : restore candidates from row truth (FS-02)}';

    protected $description = 'Sweep due SLA warning/breach timers (Doc 12 FR-10/T-04)';

    public function handle(SlaTimerSweep $sweep): int
    {
        if ((bool) $this->option('rebuild')) {
            $this->info(sprintf('rebuilt %d candidates from row truth (FS-02)', $sweep->rebuild()));

            return self::SUCCESS;
        }
        $result = $sweep->sweep((int) $this->option('limit'));
        $this->info(sprintf('warned %d, breached %d, dropped %d stale', $result['warned'], $result['breached'], $result['dropped']));

        return self::SUCCESS;
    }
}
