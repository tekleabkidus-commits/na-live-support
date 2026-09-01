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
 * the queue fact). A DIRECTED-AGENT assignment has no lawful system door yet —
 * that is packet P-DOORS' clause 3 (countersign pending); until it lands the
 * config {assignee_id} answers `unbuilt_directed_assign` honestly.
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
        if (is_string($action['assignee_id'] ?? null)) {
            return ['action' => 'assign', 'outcome' => 'failed', 'reason' => 'unbuilt_directed_assign'];
        }
        $queueId = is_string($action['queue_id'] ?? null) ? $action['queue_id'] : null;
        if ($queueId === null) {
            return ['action' => 'assign', 'outcome' => 'failed', 'reason' => 'no_target'];
        }
        if ($ticket->getAttribute('assignee_id') !== null) {
            // BR-03's spirit: a human (or an earlier rule) already won; assignment
            // is not re-litigated by a later action (EC-01 honesty, not force).
            return ['action' => 'assign', 'outcome' => 'failed', 'reason' => 'already_assigned', 'superseded_state' => true];
        }
        $superseded = $this->superseded($basis, 'ticket.queue_id', $ticket->getAttribute('queue_id'));
        $result = $this->pipeline->route($ticket, 'automation', ['explicit_queue_id' => $queueId]);

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
