<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Events\EventPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * EC-10's maintenance half + NFR-07's retention (Doc 01).
 *
 * Scheduled monthly (routes/console.php) with the monitor running daily beside it —
 * the schedule keeps the window rolling, the monitor catches the schedule failing.
 * EC-10 names exactly that failure mode: "partition maintenance failure (month
 * rollover)". Idempotent by construction: every run converges on the same window,
 * so a missed month is healed by the next run, not compounded.
 *
 * Platform-scope, no --tenant: partitions span every tenant. The DDL runs on the
 * migrator connection (see EventPartitions), so this command works only where that
 * connection is configured — which is every environment, per SEC-01's parity rule.
 */
final class PartitionsMaintain extends Command
{
    protected $signature = 'partitions:maintain';

    protected $description = 'Create future monthly events partitions and drop those beyond NFR-07 retention (Doc 01 EC-10)';

    public function handle(EventPartitions $partitions): int
    {
        $today = CarbonImmutable::now('UTC');

        $created = $partitions->ensureFutureMonths($today);
        $dropped = $partitions->dropExpired($today);

        $this->info(sprintf(
            'partitions:maintain: %d created (%s), %d dropped (%s), %d future month(s) covered.',
            count($created),
            $created === [] ? 'none' : implode(', ', $created),
            count($dropped),
            $dropped === [] ? 'none' : implode(', ', $dropped),
            $partitions->futureMonthsCovered($today),
        ));

        return self::SUCCESS;
    }
}
