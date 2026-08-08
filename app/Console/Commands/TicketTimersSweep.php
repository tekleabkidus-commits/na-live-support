<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tickets\Timers\TicketTimerSweep;
use Illuminate\Console\Command;

/**
 * T-03's 1-minute sweep (Doc 06 FR-07): close due auto-close timers, drop stale
 * candidates, and lose to customer replies (EC-01). One pass per invocation — the
 * scheduler provides the cadence, so a slow pass cannot stack behind itself.
 */
final class TicketTimersSweep extends Command
{
    protected $signature = 'tickets:timers-sweep {--limit=500 : due candidates per pass}';

    protected $description = 'Sweep due ticket auto-close timers (Doc 06 T-03/FR-07)';

    public function handle(TicketTimerSweep $sweep): int
    {
        $result = $sweep->sweep((int) $this->option('limit'));

        $this->info(sprintf(
            'closed %d, cancelled %d (EC-01), dropped %d stale, deferred %d, held %d (BR-07)',
            $result['closed'],
            $result['cancelled'],
            $result['dropped'],
            $result['deferred'],
            $result['held'],
        ));

        return self::SUCCESS;
    }
}
