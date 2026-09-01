<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Fields\ValueWriter;
use App\Models\Ticket;
use InvalidArgumentException;

/**
 * Doc 12 FR-03 — set_custom_field through the Fields value door (A-59's
 * ticket half). A definition refusal (unknown key, retired field, a value the
 * type rejects) fails THIS action honestly; the chain continues (AC-01).
 */
final class SetCustomFieldAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(private readonly ValueWriter $values) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $key = is_string($action['key'] ?? null) ? $action['key'] : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'set_custom_field', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        try {
            $this->values->setTicketField($ticket->identifier(), $key, $action['value'] ?? null, null, 'rule:'.$executionId);
        } catch (InvalidArgumentException $e) {
            return ['action' => 'set_custom_field', 'outcome' => 'failed', 'reason' => 'field_refused', 'detail' => $e->getMessage()];
        }

        return ['action' => 'set_custom_field', 'outcome' => 'ok'];
    }
}
