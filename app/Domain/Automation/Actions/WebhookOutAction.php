<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Webhooks\WebhookDeliveries;
use App\Domain\Automation\Webhooks\WebhookDestinations;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03 webhook out (Doc 01 signed) / SEC-02 / EC-13 — the arm names
 * a destination BY ID from the tenant's admin-managed allowlist (a URL on the
 * card is not a thing; ActionCards refuses one). The payload is identifiers
 * + ticket state, never message text (SEC-03's posture travels). The arm
 * QUEUES the delivery and answers `queued` — the rule never waits on a
 * remote host (action isolation, EC-13's "rule continues").
 */
final class WebhookOutAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(
        private readonly WebhookDestinations $destinations,
        private readonly WebhookDeliveries $deliveries,
    ) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $destinationId = is_string($action['destination_id'] ?? null) ? $action['destination_id'] : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'webhook_out', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        if ($destinationId === '' || DB::table('automation_webhook_destinations')->where('id', $destinationId)->doesntExist()) {
            return ['action' => 'webhook_out', 'outcome' => 'failed', 'reason' => 'unknown_destination'];
        }
        if ($this->destinations->live($destinationId) === null) {
            return ['action' => 'webhook_out', 'outcome' => 'failed', 'reason' => 'destination_inactive'];
        }
        /** @var object{rule_id: string, created_at: string}|null $execution */
        $execution = DB::table('automation_rule_executions')->where('id', $executionId)->first(['rule_id', 'created_at']);
        $payload = [
            'event' => 'automation.rule.fired',
            'rule_id' => $execution->rule_id ?? null,
            'execution_id' => $executionId,
            'occurred_at' => $execution->created_at ?? null, // C-11: the execution row's DB instant
            'ticket' => [
                'id' => $ticket->identifier(),
                'number' => $ticket->getAttribute('number'),
                'ticket_status' => $ticket->getAttribute('status'),
                'ticket_priority' => $ticket->getAttribute('priority'),
                'department_id' => $ticket->getAttribute('department_id'),
            ],
        ];
        $deliveryId = $this->deliveries->enqueue($destinationId, $executionId, $execution->rule_id ?? null, 'automation.rule.fired', $payload);

        return ['action' => 'webhook_out', 'outcome' => 'queued', 'delivery_id' => $deliveryId];
    }
}
