<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Events\EventPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * EC-10's monitor half: "monitor asserts future partitions exist" (Doc 01).
 *
 * Two red conditions, either alone fails the check:
 *
 *   1. Fewer than MONTHS_AHEAD full future months are covered — maintenance has
 *      stopped running and the window is closing.
 *   2. events_default holds ANY row — the safety net caught real data, meaning some
 *      occurred_at fell outside every pre-created partition. The row is safe (that
 *      is what the net is for) but its existence proves the window already failed
 *      once; a monitor that stayed green here would be reporting on the wrong thing.
 *
 * Exit code is the interface: scheduled daily, and any non-zero exit is the alert.
 */
final class PartitionsCheck extends Command
{
    protected $signature = 'partitions:check';

    protected $description = 'Assert future events partitions exist and the DEFAULT partition is empty (Doc 01 EC-10 monitor)';

    public function handle(EventPartitions $partitions): int
    {
        $today = CarbonImmutable::now('UTC');
        $failures = [];

        $covered = $partitions->futureMonthsCovered($today);
        if ($covered < EventPartitions::MONTHS_AHEAD) {
            $failures[] = sprintf(
                'only %d future month(s) covered, need %d — run partitions:maintain and find out why the schedule did not',
                $covered,
                EventPartitions::MONTHS_AHEAD,
            );
        }

        $strays = $partitions->defaultPartitionRows();
        if ($strays > 0) {
            $failures[] = sprintf(
                '%d row(s) in events_default — the partition window already failed once; move them into a real partition and diagnose',
                $strays,
            );
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error('partitions:check: FAIL — '.$failure);
            }

            Log::error('events partition monitor failing', [
                'failures' => $failures,
                'metric' => 'events.partition_monitor_failed',
            ]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'partitions:check: PASS (%d future month(s) covered, events_default empty).',
            $covered,
        ));

        return self::SUCCESS;
    }
}
