<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * EC-06: "Assignee deactivated with 30 open tickets → ticket.assignee_deactivated batch
 * → Doc 11 re-routes; hand-back system notes on each."
 *
 * WHAT THIS OWNS AND WHAT IT DOES NOT. Doc 06 owns the DETECTION and the batch: every
 * open-family ticket held by the deactivated user is unassigned and evented. Doc 11
 * owns the re-route, and it does not exist — so this emits the event it will consume
 * and leaves the tickets in the queue (assignee-less), which is the pre-agreed
 * deferral for FR-15/EC-06.
 *
 * THE EVENT NAME IS A FILED INTERPRETATION (A-8 iii): EC-06 requires
 * `ticket.assignee_deactivated`, and FR-26's enumerated family does not name it. It
 * enters the catalog by interpretation exactly as A-4 handled Doc 04's unnamed events,
 * rather than being invented silently or dropped because the list omitted it.
 *
 * WHY ONLY OPEN-FAMILY TICKETS. A resolved or closed ticket needs no owner — re-routing
 * finished work would put it back in someone's queue to be resolved twice.
 *
 * THE HAND-BACK NOTE is a per-ticket system note so the next agent sees WHY the ticket
 * arrived. Notes are Doc 04's writer and are structurally undeliverable to a customer
 * (N-04), which is what makes them the right vehicle: this explanation is for staff.
 */
final class AssigneeDeactivated
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<string> the ticket ids handed back
     */
    public function handBack(User $deactivated): array
    {
        $openFamily = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];

        /** @var list<Ticket> $tickets */
        $tickets = Ticket::query()
            ->where('assignee_id', $deactivated->identifier())
            ->whereIn('status', $openFamily)
            ->get()
            ->all();

        if ($tickets === []) {
            return [];
        }

        return DB::transaction(function () use ($tickets, $deactivated): array {
            $ids = [];

            foreach ($tickets as $ticket) {
                Ticket::query()->whereKey($ticket->identifier())->update([
                    'assignee_id' => null,
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);

                // Per ticket, not per batch: Doc 11 re-routes each one on its own
                // merits (department, priority, queue), and a single batch event
                // would make it guess which tickets were in it.
                $this->events->publish('ticket.assignee_deactivated', [
                    'tenant_id' => $ticket->getAttribute('tenant_id'),
                    'ticket_id' => $ticket->identifier(),
                    'conversation_id' => $ticket->conversation_id,
                    'previous_assignee_id' => $deactivated->identifier(),
                ]);

                $this->audit->record(
                    actionKey: 'ticket.assignee_deactivated',
                    entity: 'ticket',
                    entityId: $ticket->identifier(),
                    actorType: AuditActor::System,
                    payload: [
                        'ticket_id' => $ticket->identifier(),
                        'previous_assignee_id' => $deactivated->identifier(),
                    ],
                );

                $ids[] = $ticket->identifier();
            }

            return $ids;
        });
    }
}
