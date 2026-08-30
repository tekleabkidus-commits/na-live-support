<?php

declare(strict_types=1);

namespace App\Console\Commands\Routing;

use App\Domain\Routing\Rescue\RescueSweep;
use Illuminate\Console\Command;

/**
 * Doc 11 FR-16a / EC-08: `routing:rescue-sweep` — the deadline-ZSET timer sweep (heartbeat-lost
 * rescue deadlines + EC-08 courtesy holds). NoPollingLoopTest's allowlist names it through the
 * P-C3 amendment (filed BEFORE this run, countersigned 2026-08-30); every visit re-checks in a
 * transaction under DB time (EC-01's precedent).
 */
final class RoutingRescueSweep extends Command
{
    protected $signature = 'routing:rescue-sweep {--limit=500 : due candidates per pass}';

    protected $description = 'Sweep routing deadlines: heartbeat-lost rescue flags (FR-16a) and reopen courtesy holds (EC-08)';

    public function handle(RescueSweep $sweep): int
    {
        $limit = (int) $this->option('limit');
        $counts = $sweep->sweep($limit > 0 ? $limit : 500);
        $parts = [];
        foreach ($counts as $k => $v) {
            $parts[] = "{$v} {$k}";
        }
        $this->info('routing:rescue-sweep — '.($parts === [] ? 'nothing due' : implode(', ', $parts)));

        return self::SUCCESS;
    }
}
