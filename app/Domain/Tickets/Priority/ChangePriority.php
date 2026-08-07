<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Priority;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FR-17: "Priority low|normal|high|urgent: settable by staff (tickets.priority.change),
 * AI suggestion at handoff, automation rules; every change evented (SLA re-target per
 * Doc 12 policy rules)."
 *
 * THE SLA RE-TARGET IS DOC 12's AND IS NOT DECIDED HERE. FR-17 says the re-target
 * happens "per Doc 12 policy rules" — so this emits `ticket.priority_changed` with
 * old/new and stops. Deciding here whether a raise should move an SLA target would be
 * writing Doc 12's policy engine into Doc 06, and it would be invisible to the policy
 * that is supposed to own it.
 *
 * The four levels live in the enum AND in the `tickets_priority_check` CHECK, so a
 * fifth needs both changed deliberately (the same posture R-01 takes for statuses).
 */
final class ChangePriority
{
    private const LEVELS = ['low', 'normal', 'high', 'urgent'];

    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @param User|null $actor null = an automation/AI change (FR-17's other two sources) */
    public function change(Ticket $ticket, string $priority, ?User $actor): Ticket
    {
        if (! in_array($priority, self::LEVELS, true)) {
            throw new InvalidArgumentException(sprintf('unknown priority "%s" — FR-17 fixes four.', $priority));
        }

        if ($actor instanceof User && ! $this->verdicts->verdictFor($actor, 'tickets.priority.change')->allowed) {
            throw new DomainException('tickets.priority.change is required (FR-17).');
        }

        $old = $ticket->priority;

        if ($old === $priority) {
            // Nothing changed, so nothing is evented: an SLA consumer that re-targets
            // on every no-op event would thrash targets for no reason.
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $priority, $old, $actor): Ticket {
            Ticket::query()->whereKey($ticket->identifier())->update([
                'priority' => $priority,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();

            $this->events->publish('ticket.priority_changed', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'old_priority' => $old,
                'new_priority' => $priority,
                'actor_type' => $actor instanceof User ? 'staff' : 'system',
                'actor_id' => $actor?->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.priority_changed',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: $actor instanceof User ? AuditActor::Staff : AuditActor::System,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'old_priority' => $old,
                    'new_priority' => $priority,
                ],
                actorId: $actor?->identifier(),
            );

            return $ticket;
        });
    }
}
