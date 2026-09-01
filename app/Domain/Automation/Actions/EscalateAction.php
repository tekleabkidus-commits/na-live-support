<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Tickets\Escalation\EscalateTicket;
use App\Domain\Tickets\Escalation\EscalationPath;
use App\Domain\Tickets\Transitions\IllegalTransition;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;

/**
 * Doc 12 FR-03 — escalate through P-DOORS clause 4's system door (Doc 06
 * FR-23 with the RULE-AUTHORED reason). An illegal edge or an invalid target
 * fails THIS action honestly and the chain continues (AC-01).
 */
final class EscalateAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly EscalateTicket $escalate) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $reason = is_string($action['reason'] ?? null) ? $action['reason'] : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'escalate', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        try {
            $path = is_string($action['target_id'] ?? null)
                ? EscalationPath::named($action['target_id'])
                : EscalationPath::supervisorReview();
            $this->escalate->escalateBySystem($ticket, $path, $reason, 'rule:'.$executionId);
        } catch (IllegalTransition $e) {
            return ['action' => 'escalate', 'outcome' => 'failed', 'reason' => IllegalTransition::CODE, 'detail' => $e->getMessage()];
        } catch (TransitionRefused $e) {
            return ['action' => 'escalate', 'outcome' => 'failed', 'reason' => 'escalation_refused', 'detail' => $e->getMessage()];
        } catch (\DomainException $e) {
            return ['action' => 'escalate', 'outcome' => 'failed', 'reason' => 'escalation_refused', 'detail' => $e->getMessage()];
        }

        return ['action' => 'escalate', 'outcome' => 'ok'];
    }
}
