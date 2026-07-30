<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Events\OutboxRelay;
use Illuminate\Console\Command;

/**
 * The outbox relay worker (Doc 01 T-03, FS-02; §12's lag budget).
 *
 * Runs continuously on the `outbox-relay` Horizon supervisor that Session 0 already
 * provisioned. A command rather than a queued job, deliberately: a job that relays the
 * outbox would itself need to be dispatched onto a queue, and if the queue is the thing
 * that is unavailable, the recovery mechanism is unavailable with it.
 *
 * `--once` exists for tests and for a manual drain; without it the command loops with a
 * short sleep between empty passes. The sleep is only on EMPTY passes — after a productive
 * batch it immediately tries again, so a backlog drains at full speed rather than at one
 * batch per tick.
 */
final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay
        {--once : relay a single batch and exit}
        {--batch=100 : rows per pass}
        {--sleep=1 : seconds to wait after an empty pass}';

    protected $description = 'Relay committed outbox rows to the events log and event queues';

    public function handle(OutboxRelay $relay): int
    {
        $batch = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');
        $once = (bool) $this->option('once');

        do {
            $relayed = $relay->relayBatch($batch);

            if ($relayed > 0) {
                $this->info(sprintf('relayed %d event(s)', $relayed));
            }

            if ($once) {
                return self::SUCCESS;
            }

            if ($relayed === 0) {
                // Only sleep when there was nothing to do — see the class docblock.
                sleep(max(1, $sleep));
            }
        } while (true);
    }
}
