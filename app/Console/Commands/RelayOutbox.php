<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Events\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * The outbox relay worker (Doc 01 T-03, FS-02; §12's lag budget).
 *
 * Runs continuously as the dedicated `relay` compose service (advisor ruling,
 * 2026-08-13 — S07 step 14). THE HONEST CORRECTION: this docblock used to claim
 * the command "runs continuously on the outbox-relay Horizon supervisor that
 * Session 0 already provisioned" — that claim was ASPIRATIONAL AND NEVER TRUE.
 * Horizon supervisors run queue jobs, not artisan commands; no process invoked
 * this command anywhere (worker = horizon, scheduler = schedule:work, no compose
 * service), so from Doc 04's landing until the step-14 discovery, zero events
 * were ever relayed in a live stack — C-06 held only inside test processes,
 * which run consumers inline. A command rather than a queued job, deliberately:
 * a job that relays the outbox would itself need to be dispatched onto a queue,
 * and if the queue is the thing that is unavailable, the recovery mechanism is
 * unavailable with it. (That reasoning was always right; the process to run the
 * command is what was missing.)
 *
 * `--once` exists for tests and for a manual drain; without it the command loops with a
 * short sleep between empty passes. The sleep is only on EMPTY passes — after a productive
 * batch it immediately tries again, so a backlog drains at full speed rather than at one
 * batch per tick.
 *
 * PRE-SCHEMA RESILIENCE (S07 step 14, found by the condition-4 CI verdicts):
 * on a fresh stack the relay boots BEFORE migrations exist and its first
 * query died on the missing outbox table (42P01) — locally the compose
 * `unless-stopped` policy papered over the crash loop, but CI's
 * `up --wait` treats a restarting container as a failed bring-up, and
 * EVERY run since the service landed (89c1d8a) failed there: the last
 * green run predates the relay. The loop now treats "the world is not
 * ready" (undefined table 42P01, or a refused/failed connection 08xxx) as
 * an EMPTY PASS — stay alive, sleep, retry — so the process-liveness
 * health stays green while the schema arrives, in CI and on first local
 * boot alike. Every OTHER failure still crashes loudly: a genuinely broken
 * relay must die where FS-01 can see it, and the restart policy remains
 * the second layer, not the first.
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

        $waitingAnnounced = false;

        do {
            try {
                $relayed = $relay->relayBatch($batch);
            } catch (QueryException $e) {
                // The SQLSTATE lives in errorInfo[0]; getCode() is only the
                // SQLSTATE for post-connection errors — a CONNECTION failure
                // reports the pgsql driver int (7) there, which is exactly
                // how the first version of this catch stayed blind in CI
                // (run 31748210036: relay still unhealthy — postgres was not
                // accepting connections yet when the relay booted).
                $sqlstate = $e->errorInfo[0] ?? null;
                $state = is_string($sqlstate) ? $sqlstate : (string) $e->getCode();
                $notReadyYet = $state === '42P01' || str_starts_with($state, '08');
                if (! $notReadyYet || $once) {
                    throw $e;
                }
                if (! $waitingAnnounced) {
                    $this->warn('outbox schema/connection not ready yet — waiting (42P01/08xxx treated as an empty pass)');
                    $waitingAnnounced = true;
                }
                sleep(max(1, $sleep));

                continue;
            }
            $waitingAnnounced = false;

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
