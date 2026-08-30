<?php

declare(strict_types=1);

namespace App\Console\Commands\Routing;

use App\Domain\Routing\Temporary\ExpirySweep;
use Illuminate\Console\Command;

/**
 * Doc 11 T-07: the 1-minute temporary-membership expiry sweep (NoPollingLoopTest
 * allowlist entry `routing:expiry-sweep` — a deadline-ZSET timer, the Doc 06 shape, not a
 * queue poll). Cleanup only: security is the resolver's DB-time check (FS-05).
 */
final class RoutingExpirySweep extends Command
{
    protected $signature = 'routing:expiry-sweep {--limit=500 : due candidates per pass}';

    protected $description = 'End expired temporary queue/department memberships (Doc 11 T-07/EC-07): rows ended, queue.temporary_grant.expired, routing.pull_in.ended';

    public function handle(ExpirySweep $sweep): int
    {
        $limit = (int) $this->option('limit');
        $result = $sweep->sweep($limit > 0 ? $limit : 500);
        $this->info(sprintf('routing:expiry-sweep — %d ended, %d dropped, %d deferred', $result['ended'], $result['dropped'], $result['deferred']));

        return self::SUCCESS;
    }
}
