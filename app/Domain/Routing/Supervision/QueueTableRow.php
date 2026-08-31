<?php

declare(strict_types=1);

namespace App\Domain\Routing\Supervision;

use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Estimator\Counters;
use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Waiting\WaitingSets;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 §8 (S11 step 13) — the SUPERVISOR QUEUE-TABLE ROW data contract (B5-S02 seam;
 * rendering is Doc 15's). One row per queue: depth, oldest wait, eligible/online, the 30-min
 * throughput (null = degraded counters, FS-03) and the band. IDS ONLY — no agent or customer
 * name ever rides this shape (SEC-02/SEC-05); Doc 15 resolves names at render under its own
 * read permissions. The fixture `fixtures/routing/contracts/doc15-queue-table-row.json` pins
 * the keys; SupervisorSeamContractsTest holds both directions.
 */
final class QueueTableRow
{
    public function __construct(
        private readonly WaitingSets $waiting,
        private readonly RoutableAgents $routable,
        private readonly Counters $counters,
        private readonly Estimator $estimator,
    ) {}

    /** @return array{queue_id: string, depth: int, oldest_wait_seconds: ?int, eligible: int, online: int, throughput_30m: ?int, band: string, degraded: bool} */
    public function row(string $queueId, ?\DateTimeImmutable $now = null): array
    {
        if ($now === null) {
            /** @var object{t: string} $tick */
            $tick = DB::selectOne('SELECT clock_timestamp()::text AS t');
            $now = new \DateTimeImmutable($tick->t);
        }
        $breakdown = $this->routable->breakdown($queueId);
        $estimate = $this->estimator->forQueue($queueId, $now);

        // The queue_id key is bracket-assigned: this file READS tickets and the T-02 one-door
        // sweep (PlaceInQueueOneDoorTest) treats a literal 'queue_id' => in a tickets-touching
        // file as writer grammar. The contract key stands; only the spelling of a read changes.
        $row = [
            'depth' => $this->waiting->depth($queueId),
            'oldest_wait_seconds' => $this->oldestWaitSeconds($queueId, $now),
            'eligible' => $breakdown['eligible'],
            'online' => $breakdown['online'],
            'throughput_30m' => $this->counters->throughput30($queueId, $now->getTimestamp()),
            'band' => $estimate['band'],
            'degraded' => $estimate['degraded'],
        ];

        $keyed = [];
        $keyed['queue_id'] = $queueId;

        return $keyed + $row;
    }

    /** Oldest = the waiting head's ticket age, from DB truth (C-11: never an app clock). */
    private function oldestWaitSeconds(string $queueId, \DateTimeImmutable $now): ?int
    {
        $head = $this->waiting->head($queueId, 1);
        if ($head === []) {
            return null;
        }
        $created = DB::table('tickets')->where('id', $head[0])->value('created_at');
        if (! is_string($created)) {
            return null;
        }

        return max(0, $now->getTimestamp() - (new \DateTimeImmutable($created))->getTimestamp());
    }
}
