<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Tickets\Transfer\TransferTicket;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use DomainException;

/**
 * Doc 12 FR-03 — transfer_department through P-DOORS clause 1's system door.
 * A refusal (empty destination, a door rule) is the honest outcome; the chain
 * continues (AC-01).
 */
final class TransferDepartmentAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly TransferTicket $transfer) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $departmentId = is_string($action['department_id'] ?? null) ? $action['department_id'] : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'transfer_department', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        $superseded = $this->superseded($basis, 'ticket.department_id', $ticket->getAttribute('department_id'));
        try {
            $this->transfer->transferBySystem($ticket, $departmentId, 'rule:'.$executionId, is_string($action['queue_id'] ?? null) ? $action['queue_id'] : null);
        } catch (DomainException|TransitionRefused $e) {
            return ['action' => 'transfer_department', 'outcome' => 'failed', 'reason' => 'transfer_refused', 'detail' => $e->getMessage()];
        }
        $outcome = ['action' => 'transfer_department', 'outcome' => 'ok'];
        if ($superseded) {
            $outcome['superseded_state'] = true;
        }

        return $outcome;
    }
}
