<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Models\Ticket;

/**
 * Doc 12 FR-03/BR-02/§7 — assign VIA DOC 11, literally: the arm hands the
 * ticket to RoutingPipeline::route with trigger=automation (FR-17's own
 * vocabulary — the decision record shows the automation trigger distinctly)
 * and the EXPLICIT QUEUE as FR-08 step 2's first rung. Placement, ranking,
 * strategy assignment, capacity, RG finality and the record all belong to the
 * engine — this arm re-implements NONE of it (EC-01's superseded honesty rides
 * the queue fact). A DIRECTED-AGENT assignment rides P-DOORS clause 3's
 * pipeline hint (countersigned, option a): tried FIRST, never instead — the
 * engine stays the one assigner and every guard (capacity, eligibility, RG
 * finality) applies unchanged.
 */
final class AssignAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly RoutingPipeline $pipeline) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        if (! $ticket instanceof Ticket) {
            return ['action' => 'assign', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        $directed = is_string($action['assignee_id'] ?? null) ? $action['assignee_id'] : null;
        $queueId = is_string($action['queue_id'] ?? null) ? $action['queue_id'] : null;
        if ($queueId === null && $directed === null) {
            return ['action' => 'assign', 'outcome' => 'failed', 'reason' => 'no_target'];
        }
        if ($ticket->getAttribute('assignee_id') !== null) {
            // BR-03's spirit: a human (or an earlier rule) already won; assignment
            // is not re-litigated by a later action (EC-01 honesty, not force).
            return ['action' => 'assign', 'outcome' => 'failed', 'reason' => 'already_assigned', 'superseded_state' => true];
        }
        $superseded = $this->superseded($basis, 'ticket.queue_id', $ticket->getAttribute('queue_id'));
        // P-DOORS clause 3 (countersigned, option a): the directed assignee is a
        // PIPELINE HINT — tried first, every guard applies, RG finality upstream.
        $result = $this->pipeline->route($ticket, 'automation', array_filter([
            'explicit_queue_id' => $queueId,
            'explicit_assignee_id' => $directed,
        ], static fn ($v): bool => $v !== null));

        $outcome = ['action' => 'assign', 'outcome' => $result['outcome'] === 'parked_error' ? 'failed' : 'ok', 'routing_outcome' => $result['outcome']];
        if ($result['outcome'] === 'parked_error') {
            $outcome['reason'] = 'routing_parked';
        }
        if ($superseded) {
            $outcome['superseded_state'] = true;
        }

        return $outcome;
    }
}
