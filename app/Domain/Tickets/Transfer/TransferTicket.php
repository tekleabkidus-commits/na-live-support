<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transfer;

use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\Scopes\MembershipSource;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-22's transfer: a department/queue change under `tickets.department.change`,
 * status untouched — "re-routes per Doc 11, resets assignee unless the target
 * department retains them, emits ticket.transferred".
 *
 * SEC-01 ON THE DESTINATION: "moving work cannot smuggle visibility." The actor's
 * scope is asked about the DESTINATION, by level:
 *   All        anywhere
 *   Own        only a ticket that stays THEIRS — the actor is its assignee and
 *              survives retention (their Own scope follows the assignment, so the
 *              destination cannot take the ticket out of their sight)
 *   Team/Dept  the destination must be in their membership — and pre-Doc 11 that is
 *              EmptyMembership, so these levels FAIL CLOSED (target_out_of_scope),
 *              the step-4 posture exactly
 *
 * RETENTION IS THE SAME QUESTION asked about the ASSIGNEE: would they still see the
 * ticket in the new department? All/Own → yes (Own follows the assignment — A-11's
 * reading); Team/Dept → membership, fail-closed pre-Doc 11 → reset to the queue,
 * which is what "re-routes per Doc 11" means until a router exists (the event is the
 * seam). The reset emits FR-15's ticket.assigned {old, null} alongside the transfer.
 *
 * DEPARTMENTS ARE OPAQUE IDS until Doc 11's structures land — the schema's own
 * posture (tickets.department_id carries no FK for the same reason). Existence
 * validation is Doc 11's arrival gift; recorded, not invented. The SLA instance
 * keeps its original targets unless Doc 12's policy says re-target — Doc 12 consumes
 * ticket.transferred for exactly that; nothing to do here but say so.
 */
final class TransferTicket
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly MembershipSource $membership,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly \App\Domain\Tickets\Placement\PlaceInQueue $placement,
    ) {}

    /**
     * @param  string|null  $queueId  FR-22's queue half (S11 STOP-A packet): when a transfer names a
     *                                queue, PlaceInQueue — the one queue_id writer — places it inside
     *                                this transaction; null keeps the ticket queue-less (routing's
     *                                transferred trigger re-places it, Doc 11 FR-07).
     */
    public function transfer(Ticket $ticket, string $departmentId, User $actor, ?string $queueId = null): Ticket
    {
        if (! $this->verdicts->verdictFor($actor, 'tickets.department.change')->allowed) {
            throw new DomainException('tickets.department.change is required (FR-22).');
        }

        if (trim($departmentId) === '') {
            throw new DomainException('a transfer names a destination department (FR-22).');
        }

        if (! $this->destinationInScope($actor, $ticket, $departmentId)) {
            throw new TransitionRefused(
                'target_out_of_scope',
                'the destination department is outside your scope (SEC-01) — moving work cannot smuggle visibility.',
            );
        }

        return $this->perform($ticket, $departmentId, $actor, null, $queueId);
    }

    /**
     * PACKET P-DOORS clause 1 (S12, countersigned at the batch-B acceptance):
     * the SYSTEM transfer — Doc 12 FR-03's arm. No User travels (the
     * TransitionContext misattribution law); the SEC-01 activator floor is the
     * rule's own gate; staff scope is a staff concept and does not apply to a
     * tenant-scoped rule (stated). Events/audits carry actor system + rule_ref.
     */
    public function transferBySystem(Ticket $ticket, string $departmentId, string $ruleRef, ?string $queueId = null): Ticket
    {
        if (trim($departmentId) === '') {
            throw new DomainException('a transfer names a destination department (FR-22).');
        }

        return $this->perform($ticket, $departmentId, null, $ruleRef, $queueId);
    }

    private function perform(Ticket $ticket, string $departmentId, ?User $actor, ?string $ruleRef, ?string $queueId): Ticket
    {
        $oldDepartmentId = $ticket->department_id;
        $assigneeId = $ticket->assignee_id;
        $retained = $assigneeId !== null && $this->assigneeRetained($assigneeId, $departmentId);

        return DB::transaction(function () use ($ticket, $departmentId, $actor, $ruleRef, $oldDepartmentId, $assigneeId, $retained, $queueId): Ticket {
            $update = ['department_id' => $departmentId, 'updated_at' => DB::raw('clock_timestamp()')];

            if ($assigneeId !== null && ! $retained) {
                $update['assignee_id'] = null;
            }

            Ticket::query()->whereKey($ticket->identifier())->update($update);
            $ticket->refresh();

            if ($queueId !== null) {
                $this->placement->place($ticket, $queueId, $actor, 'transfer');
            }

            if ($assigneeId !== null && ! $retained) {
                // FR-15: "every change emits ticket.assigned" — the reset is a change.
                $this->events->publish('ticket.assigned', [
                    'tenant_id' => $ticket->getAttribute('tenant_id'),
                    'ticket_id' => $ticket->identifier(),
                    'conversation_id' => $ticket->conversation_id,
                    'old_assignee_id' => $assigneeId,
                    'new_assignee_id' => null,
                    'by' => $actor?->identifier(),
                ]);

                $this->audit->record(
                    actionKey: 'ticket.assigned',
                    entity: 'ticket',
                    entityId: $ticket->identifier(),
                    actorType: $actor instanceof User ? AuditActor::Staff : AuditActor::System,
                    payload: [
                        'ticket_id' => $ticket->identifier(),
                        'old_assignee_id' => $assigneeId,
                        'new_assignee_id' => null,
                    ] + ($ruleRef === null ? [] : ['rule_ref' => $ruleRef]),
                    actorId: $actor?->identifier(),
                );
            }

            $this->events->publish('ticket.transferred', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'old_department_id' => $oldDepartmentId,
                'new_department_id' => $departmentId,
                'assignee_retained' => $retained,
                'actor_type' => $actor instanceof User ? 'staff' : 'system',
                'actor_id' => $actor?->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.transferred',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: $actor instanceof User ? AuditActor::Staff : AuditActor::System,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'old_department_id' => $oldDepartmentId,
                    'new_department_id' => $departmentId,
                    'assignee_retained' => $retained,
                ] + ($ruleRef === null ? [] : ['rule_ref' => $ruleRef]),
                actorId: $actor?->identifier(),
            );

            return $ticket;
        });
    }

    private function destinationInScope(User $actor, Ticket $ticket, string $departmentId): bool
    {
        $level = $this->verdicts->mapFor($actor)->scopeFor('tickets');

        return match ($level) {
            'All' => true,
            'Own' => $ticket->assignee_id === $actor->identifier() && $this->assigneeRetained($actor->identifier(), $departmentId),
            'Team', 'Dept' => in_array($departmentId, $this->membership->departmentIdsFor($actor), true),
            default => false,
        };
    }

    private function assigneeRetained(string $assigneeId, string $departmentId): bool
    {
        $assignee = User::query()->find($assigneeId);

        if (! $assignee instanceof User || $assignee->isDisabled()) {
            return false;
        }

        $level = $this->verdicts->mapFor($assignee)->scopeFor('tickets');

        return match ($level) {
            // Own follows the assignment (A-11), All follows everything.
            'All', 'Own' => true,
            'Team', 'Dept' => in_array($departmentId, $this->membership->departmentIdsFor($assignee), true),
            default => false,
        };
    }
}
