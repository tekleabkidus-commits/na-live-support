<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversations\Storage\MessagePartitions;
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
 *
 * Since Doc 04 the same two assertions run for the messages table (Doc 04 T-01/R-01,
 * FS-04): a row in messages_default is the "write to a missing partition" FS-04 says
 * should never fire — this monitor IS the pager path, and its test drives a row into
 * the default partition to prove the pager exists.
 */
final class PartitionsCheck extends Command
{
    protected $signature = 'partitions:check';

    protected $description = 'Assert future events + messages partitions exist and the DEFAULT partitions are empty (Doc 01 EC-10, Doc 04 FS-04)';

    public function handle(EventPartitions $eventPartitions, MessagePartitions $messagePartitions): int
    {
        $today = CarbonImmutable::now('UTC');
        $failures = [];

        $surfaces = [
            'events' => [
                'covered' => $eventPartitions->futureMonthsCovered($today),
                'ahead' => EventPartitions::MONTHS_AHEAD,
                'strays' => $eventPartitions->defaultPartitionRows(),
            ],
            'messages' => [
                'covered' => $messagePartitions->futureMonthsCovered($today),
                'ahead' => MessagePartitions::MONTHS_AHEAD,
                'strays' => $messagePartitions->defaultPartitionRows(),
            ],
        ];

        foreach ($surfaces as $table => $facts) {
            if ($facts['covered'] < $facts['ahead']) {
                $failures[] = sprintf(
                    '%s: only %d future month(s) covered, need %d — run partitions:maintain and find out why the schedule did not',
                    $table,
                    $facts['covered'],
                    $facts['ahead'],
                );
            }

            if ($facts['strays'] > 0) {
                $failures[] = sprintf(
                    '%s: %d row(s) in %s_default — the partition window already failed once; move them into a real partition and diagnose',
                    $table,
                    $facts['strays'],
                    $table,
                );
            }
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error('partitions:check: FAIL — '.$failure);
            }

            Log::error('partition monitor failing', [
                'failures' => $failures,
                'metric' => 'partitions.monitor_failed',
            ]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'partitions:check: PASS (events %d, messages %d future month(s) covered; both DEFAULT partitions empty).',
            $surfaces['events']['covered'],
            $surfaces['messages']['covered'],
        ));

        return self::SUCCESS;
    }
}
