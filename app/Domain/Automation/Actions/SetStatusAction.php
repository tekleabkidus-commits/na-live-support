<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Tickets\Transitions\IllegalTransition;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;

/**
 * Doc 12 FR-03/BR-02/AC-01 — set_status THROUGH Doc 06's transition service,
 * actor system (FR-04's own vocabulary; TransitionContext refuses a User on a
 * system act — misattribution is structurally impossible). An ILLEGAL
 * transition FAILS THIS ACTION with the service's own reason and the chain
 * continues — §21's "no bypass powers" line, executed.
 */
final class SetStatusAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly TicketTransitionService $transitions) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $to = is_string($action['status'] ?? null) ? TicketStatus::tryFrom($action['status']) : null;
        if ($ticket === null || $to === null) {
            return ['action' => 'set_status', 'outcome' => 'failed', 'reason' => $ticket === null ? 'no_ticket' : 'unknown_status'];
        }
        $superseded = $this->superseded($basis, 'ticket.status', $ticket->getAttribute('status'));
        try {
            $this->transitions->transition($ticket, $to, TransitionContext::system('automation rule'));
        } catch (IllegalTransition $e) {
            return ['action' => 'set_status', 'outcome' => 'failed', 'reason' => IllegalTransition::CODE, 'detail' => $e->getMessage(), 'superseded_state' => $superseded];
        }

        $outcome = ['action' => 'set_status', 'outcome' => 'ok'];
        if ($superseded) {
            $outcome['superseded_state'] = true;
        }

        return $outcome;
    }
}
