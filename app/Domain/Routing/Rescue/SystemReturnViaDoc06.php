<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use App\Domain\Conversations\Storage\MessageRepository;
use App\Domain\Routing\Record\RoutingDecisionRecord;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;

/**
 * P-C6 (S11 step 9b) — the SystemReturn port bound to Doc 06's `AssignTicket::unassignBySystem()`.
 * Order of one act: the FR-17 record FIRST (trigger `rescue` — or the hold's own record id for EC-08,
 * passed in), then the Doc 06 door (the only assignee write), then — for a rescue — the system note
 * (`routing_rescue_returned`, N-03), `rescue.auto_returned` and `routing.rescue.auto_returned`
 * (system). Doc 11's unassigned trigger re-routes the ticket on the `ticket.assigned{new: null}` it
 * emits; AssignmentStats frees the agent's slot on the same event.
 */
final class SystemReturnViaDoc06 implements SystemReturn
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AssignTicket $assign,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly MessageRepository $messages,
    ) {}

    public function returnToQueue(Ticket $ticket, string $reason, ?string $decisionId = null): Ticket
    {
        $previous = $ticket->assignee_id;
        $queueId = $ticket->getAttribute('queue_id');
        $queueId = is_string($queueId) ? $queueId : null;
        $departmentId = $ticket->getAttribute('department_id');

        if ($decisionId === null) {
            $record = new RoutingDecisionRecord($this->context, $this->events, $ticket->identifier(), $ticket->conversation_id, 'rescue');
            $record->step('rescue_return', ['previous_assignee_id' => $previous, 'reason' => $reason], 'returned to the queue by the system (FR-16a, routing.rescue.auto) — re-routed on the unassigned trigger');
            $record->strategy(null, null);
            $decisionId = $record->close('waiting', $queueId, is_string($departmentId) ? $departmentId : null, null);
        }

        $returned = $this->assign->unassignBySystem($ticket, $reason, $decisionId);

        if (str_starts_with($reason, 'rescue:')) {
            $conversation = Conversation::query()->find($ticket->conversation_id);
            if ($conversation instanceof Conversation) {
                $this->messages->persistSystemMarker($conversation, 'routing_rescue_returned', ['previous_assignee_id' => $previous, 'reason' => $reason]);
            }
            $this->events->publish('rescue.auto_returned', [
                'tenant_id' => $this->context->tenantId(), 'ticket_id' => $ticket->identifier(), 'conversation_id' => $ticket->conversation_id, 'queue_id' => $queueId, 'previous_assignee_id' => $previous,
            ]);
            $this->audit->record('routing.rescue.auto_returned', 'ticket', $ticket->identifier(), AuditActor::System, [
                'ticket_id' => $ticket->identifier(), 'queue_id' => $queueId, 'previous_assignee_id' => $previous, 'routing_decision_id' => $decisionId,
            ]);
        }

        return $returned;
    }
}
