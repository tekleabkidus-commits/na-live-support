<?php

declare(strict_types=1);

namespace App\Domain\Routing\Dispatch;

use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Models\Ticket;

/**
 * Doc 11 FR-08 step 5 / T-03 / NFR-02 / FS-02 / EC-01 / EC-03 — THE PER-QUEUE DISPATCHER, a
 * pure event reaction: an availability event (agent online, slot freed, membership change)
 * names the queues to run; for each, pop the ZSET HEAD (read-then-commit: head() reads,
 * remove() only after the pipeline reports `assigned`) → the pipeline attempts assignment
 * (optimistic; race → next candidate inside the pipeline) → on `waiting` the ticket stays
 * (and the loop stops: nobody routable); on `assigned` remove and continue with the next
 * head while agents remain. No polling loop anywhere (NoPollingLoopTest).
 */
final class QueueDispatcher
{
    public function __construct(
        private readonly WaitingSets $waiting,
        private readonly RoutingPipeline $pipeline,
        private readonly RoutableAgents $routable,
    ) {}

    /** @return array{assigned: int, waiting: int, skipped: int} */
    public function run(string $queueId, int $maxAssignments = 100): array
    {
        $assigned = 0;
        $skipped = 0;
        for ($i = 0; $i < $maxAssignments; $i++) {
            if ($this->routable->forQueue($queueId) === []) {
                break;   // FR-08 step 5: nobody to assign to — the tickets wait for the next event
            }
            $head = $this->waiting->head($queueId, 1);
            if ($head === []) {
                break;
            }
            $ticketId = $head[0];
            $ticket = Ticket::query()->find($ticketId);
            $placedIn = $ticket instanceof Ticket ? $ticket->getAttribute('queue_id') : null;
            if (! $ticket instanceof Ticket || $ticket->assignee_id !== null || ! is_string($placedIn) || $placedIn !== $queueId
                || ! in_array((string) $ticket->status, ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'], true)) {
                // Stale member (already assigned elsewhere, moved, closed): the mirror drifted — drop it.
                $this->waiting->remove($queueId, $ticketId);
                $skipped++;

                continue;
            }
            $result = $this->pipeline->route($ticket, 'availability', ['explicit_department_id' => is_string($ticket->department_id) ? $ticket->department_id : null, 'explicit_queue_id' => $queueId]);
            if ($result['outcome'] === 'assigned' || $result['outcome'] === 'manual') {
                $this->waiting->remove($queueId, $ticketId);   // FS-02: removal ONLY after the commit
                $assigned++;

                continue;
            }
            break;   // waiting (or parked): stop — the next availability event re-runs
        }

        return ['assigned' => $assigned, 'waiting' => $this->waiting->depth($queueId), 'skipped' => $skipped];
    }
}
