<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Tickets\Priority\ChangePriority;
use InvalidArgumentException;

/**
 * Doc 12 FR-03/BR-02 — set_priority through Doc 06's ChangePriority with the
 * NULL actor its own docblock names for automation (FR-17's non-staff source);
 * the door publishes ticket.priority_changed with actor_type=system, which is
 * FR-01's priority trigger AND the SLA retarget signal (EC-03) in one event.
 */
final class SetPriorityAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly ChangePriority $priority) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $level = is_string($action['priority'] ?? null) ? $action['priority'] : '';
        if ($ticket === null) {
            return ['action' => 'set_priority', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        $superseded = $this->superseded($basis, 'ticket.priority', $ticket->getAttribute('priority'));
        try {
            $this->priority->change($ticket, $level, null);
        } catch (InvalidArgumentException $e) {
            return ['action' => 'set_priority', 'outcome' => 'failed', 'reason' => 'unknown_priority', 'detail' => $e->getMessage()];
        }
        $outcome = ['action' => 'set_priority', 'outcome' => 'ok'];
        if ($superseded) {
            $outcome['superseded_state'] = true;
        }

        return $outcome;
    }
}
