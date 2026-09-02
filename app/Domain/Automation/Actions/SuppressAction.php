<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Suppression\MarketingSuppression;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03 suppress — sets the marketing-suppression flag on the
 * ticket's customer (Doc 08 FR-15's [D12] consumer contract), rule-authored
 * reason, system actor + rule_ref. An already-live suppression is the
 * `already_suppressed` refusal (the already_assigned precedent: nothing
 * changed, said plainly).
 */
final class SuppressAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly MarketingSuppression $suppression) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $reason = is_string($action['reason'] ?? null) ? trim($action['reason']) : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'suppress', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        if ($reason === '') {
            return ['action' => 'suppress', 'outcome' => 'failed', 'reason' => 'empty_reason'];
        }
        $customerId = $ticket->getAttribute('customer_id');
        if (! is_string($customerId) || $customerId === '') {
            return ['action' => 'suppress', 'outcome' => 'failed', 'reason' => 'no_customer'];
        }
        /** @var string|null $ruleId */
        $ruleId = DB::table('automation_rule_executions')->where('id', $executionId)->value('rule_id');
        $id = $this->suppression->suppress($customerId, $reason, 'rule', 'rule:'.$executionId, is_string($ruleId) ? $ruleId : null);
        if ($id === null) {
            return ['action' => 'suppress', 'outcome' => 'failed', 'reason' => 'already_suppressed'];
        }

        return ['action' => 'suppress', 'outcome' => 'ok', 'suppression_id' => $id];
    }
}
