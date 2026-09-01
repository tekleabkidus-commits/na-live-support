<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Escalation;

use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Tickets\Followers\TicketFollowers;
use App\Domain\Tickets\Storage\TicketScope;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-23's escalation, both directions, plus EC-05's later-disabled fallback.
 *
 *   escalate      path ∈ {supervisor_review, department:{id}, named:{user}} +
 *                 mandatory reason ≥ 10 chars (BR-06: "mandatory articulation" — the
 *                 open→escalated EDGE enforces both the permission and the length, so
 *                 this class never re-implements either); status → escalated through
 *                 the sole writer; the original assignee auto-follows; the target is
 *                 notified via ticket.escalated (Doc 16's consumption).
 *
 *   de-escalate   escalated → open "with a note" — the note is the actor's own
 *                 articulation, written through Doc 04's sanctioned writer; the
 *                 escalation payload clears (history stays in the events, FR-24's
 *                 principle).
 *
 *   EC-05         a NAMED target deactivated after the fact: the escalation falls
 *                 back to supervisor_review with a system note, and
 *                 ticket.escalation_rerouted (A-15) tells Doc 16 to notify the
 *                 escalator. Invoked from the same deactivation seam as
 *                 AssigneeDeactivated::handBack — whoever deactivates a user runs
 *                 both.
 *
 * THE ESCALATION PAYLOAD is FR-01's `escalation {path, reason, target, at}` jsonb,
 * with `at` stamped by clock_timestamp() IN the update statement (no app clock — the
 * C-11 posture even for record-keeping), plus `by` — the escalator's id — which EC-05
 * makes necessary: "notification to the escalator" is impossible if nobody recorded
 * who escalated. Filed as A-15's rider.
 *
 * A NAMED TARGET IS VALIDATED AT ESCALATE TIME: a disabled user, or one who could not
 * see the ticket, is refused escalation_target_invalid — escalating into a void
 * would be EC-05's fallback by another name, minus the honesty. Department targets
 * are opaque ids until Doc 11's structures exist (the department_id precedent).
 */
final class EscalateTicket
{
    public function __construct(
        private readonly TicketTransitionService $transitions,
        private readonly TicketFollowers $followers,
        private readonly TicketScope $scope,
        private readonly NotesWriter $notes,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function escalate(Ticket $ticket, User $actor, EscalationPath $path, string $reason): Ticket
    {
        if ($path->kind === EscalationPath::NAMED) {
            $target = User::query()->find($path->targetId);

            if (! $target instanceof User || $target->isDisabled() || ! $this->scope->visibleOnceAssignedTo($target, $ticket)) {
                throw new TransitionRefused(
                    'escalation_target_invalid',
                    'the named escalation target is disabled or cannot see this ticket (FR-23) — pick another target or supervisor review.',
                );
            }
        }

        return DB::transaction(function () use ($ticket, $actor, $path, $reason): Ticket {
            // The payload FIRST, in the same transaction as the transition: an
            // escalated ticket with no escalation record would be BR-06's silent
            // shove. `at` is the database's own clock, in the statement.
            DB::update(
                "UPDATE tickets
                    SET escalation = jsonb_build_object(
                            'path', ?::text,
                            'reason', ?::text,
                            'target', ?::text,
                            'at', to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),
                            'by', ?::text
                        ),
                        updated_at = clock_timestamp()
                  WHERE id = ?",
                [$path->kind, $reason, $path->targetId, $actor->identifier(), $ticket->identifier()],
            );
            $ticket->refresh();

            // The machine's edge enforces tickets.escalate AND the ≥10-char reason.
            $this->transitions->transition($ticket, TicketStatus::Escalated, TransitionContext::staff($actor, $reason));
            $ticket->refresh();

            // FR-23: "original assignee auto-follows" — kept attached to work that
            // was theirs, notified as a follower from here on.
            $assigneeId = $ticket->assignee_id;
            if ($assigneeId !== null) {
                $assignee = User::query()->find($assigneeId);
                if ($assignee instanceof User) {
                    $this->followers->autoFollow($ticket, $assignee);
                }
            }

            // "target notified" — Doc 16 consumes this against path/target.
            $this->events->publish('ticket.escalated', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'path' => $path->kind,
                'target_id' => $path->targetId,
                'reason' => $reason,
                'actor_type' => 'staff',
                'actor_id' => $actor->identifier(),
            ]);

            // SEC-03: "escalation reasons always audited with actor + old/new."
            $this->audit->record(
                actionKey: 'ticket.escalated',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'path' => $path->kind,
                    'target_id' => $path->targetId,
                    'reason' => $reason,
                ],
                actorId: $actor->identifier(),
            );

            return $ticket;
        });
    }

    /**
     * PACKET P-DOORS clause 4 (S12, countersigned; the plan-approved P-ESC shape):
     * the SYSTEM escalation — Doc 12 FR-03's arm AND FR-08's breach-escalation
     * hook share this ONE door. The named-target validation, the escalation
     * payload, the machine edge (TransitionContext::system — the reason still
     * travels), the auto-follow and the event all ride the staff path's own
     * internals; actor system + rule_ref on the record.
     */
    public function escalateBySystem(Ticket $ticket, EscalationPath $path, string $reason, string $ruleRef): Ticket
    {
        if ($path->kind === EscalationPath::NAMED) {
            $target = User::query()->find($path->targetId);

            if (! $target instanceof User || $target->isDisabled() || ! $this->scope->visibleOnceAssignedTo($target, $ticket)) {
                throw new TransitionRefused(
                    'escalation_target_invalid',
                    'the named escalation target is disabled or cannot see this ticket (FR-23) — pick another target or supervisor review.',
                );
            }
        }

        return DB::transaction(function () use ($ticket, $path, $reason, $ruleRef): Ticket {
            DB::update(
                "UPDATE tickets
                    SET escalation = jsonb_build_object(
                            'path', ?::text,
                            'reason', ?::text,
                            'target', ?::text,
                            'at', to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),
                            'by', 'system'
                        ),
                        updated_at = clock_timestamp()
                  WHERE id = ?",
                [$path->kind, $reason, $path->targetId, $ticket->identifier()],
            );
            $ticket->refresh();

            $this->transitions->transition($ticket, TicketStatus::Escalated, TransitionContext::system($reason));
            $ticket->refresh();

            $assigneeId = $ticket->assignee_id;
            if ($assigneeId !== null) {
                $assignee = User::query()->find($assigneeId);
                if ($assignee instanceof User) {
                    $this->followers->autoFollow($ticket, $assignee);
                }
            }

            $this->events->publish('ticket.escalated', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'path' => $path->kind,
                'target_id' => $path->targetId,
                'reason' => $reason,
                'actor_type' => 'system',
                'actor_id' => null,
            ]);

            $this->audit->record(
                actionKey: 'ticket.escalated',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::System,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'path' => $path->kind,
                    'target_id' => $path->targetId,
                    'reason' => $reason,
                    'rule_ref' => $ruleRef,
                ],
            );

            return $ticket;
        });
    }

    /** FR-23: "de-escalation returns to open with a note." */
    public function deescalate(Ticket $ticket, User $actor, string $note): Ticket
    {
        if (trim($note) === '') {
            throw new DomainException('de-escalation carries a note (FR-23) — an empty one explains nothing.');
        }

        /** @var array<string, mixed>|null $escalation */
        $escalation = $ticket->escalation;

        return DB::transaction(function () use ($ticket, $actor, $note, $escalation): Ticket {
            $this->transitions->transition($ticket, TicketStatus::Open, TransitionContext::staff($actor, $note));
            $ticket->refresh();

            /** @var Conversation $conversation */
            $conversation = Conversation::query()->findOrFail($ticket->conversation_id);
            $this->notes->addNote($conversation, $actor, $note);

            // The CURRENT escalation clears; its history lives in the events and the
            // audit trail (FR-24's "history preserved in events" principle).
            DB::update('UPDATE tickets SET escalation = NULL, updated_at = clock_timestamp() WHERE id = ?', [$ticket->identifier()]);
            $ticket->refresh();

            $this->events->publish('ticket.deescalated', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'previous_path' => is_array($escalation) ? ($escalation['path'] ?? null) : null,
                'actor_type' => 'staff',
                'actor_id' => $actor->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.deescalated',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'previous_escalation' => $escalation,
                    'note' => $note,
                ],
                actorId: $actor->identifier(),
            );

            return $ticket;
        });
    }

    /**
     * EC-05: "Escalation to a named user who is later disabled/out-of-scope →
     * auto-fallback to supervisor_review with a system note + notification to the
     * escalator." One pass over the escalated set naming the gone user; invoked from
     * the deactivation seam, beside AssigneeDeactivated::handBack.
     *
     * @return list<string> the ticket ids re-routed
     */
    public function onUserDeactivated(User $gone): array
    {
        /** @var list<Ticket> $tickets */
        $tickets = Ticket::query()
            ->where('status', TicketStatus::Escalated->value)
            ->whereRaw("escalation->>'path' = ? AND escalation->>'target' = ?", [EscalationPath::NAMED, $gone->identifier()])
            ->get()
            ->all();

        if ($tickets === []) {
            return [];
        }

        return DB::transaction(function () use ($tickets, $gone): array {
            $ids = [];

            foreach ($tickets as $ticket) {
                // Fall back IN PLACE: original reason, at and by survive; the path
                // becomes supervisor_review and the fallback is stamped in DB time.
                DB::update(
                    "UPDATE tickets
                        SET escalation = (escalation - 'target')
                                || jsonb_build_object(
                                    'path', ?::text,
                                    'target', NULL,
                                    'fallback_from', ?::text,
                                    'fallback_at', to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')
                                ),
                            updated_at = clock_timestamp()
                      WHERE id = ?",
                    [EscalationPath::SUPERVISOR_REVIEW, $gone->identifier(), $ticket->identifier()],
                );
                $ticket->refresh();

                /** @var Conversation $conversation */
                $conversation = Conversation::query()->findOrFail($ticket->conversation_id);
                $this->notes->addSystemNote($conversation, __('tickets.note.escalation_rerouted', [
                    'number' => (string) $ticket->number,
                ]));

                /** @var array<string, mixed> $escalation */
                $escalation = $ticket->escalation ?? [];
                $escalatorId = is_string($escalation['by'] ?? null) ? $escalation['by'] : null;

                // A-15: the event Doc 16 turns into the escalator's notification.
                $this->events->publish('ticket.escalation_rerouted', [
                    'tenant_id' => $ticket->getAttribute('tenant_id'),
                    'ticket_id' => $ticket->identifier(),
                    'from_target_user_id' => $gone->identifier(),
                    'notify_user_id' => $escalatorId,
                    'reason' => 'target_deactivated',
                ]);

                $this->audit->record(
                    actionKey: 'ticket.escalation_rerouted',
                    entity: 'ticket',
                    entityId: $ticket->identifier(),
                    actorType: AuditActor::System,
                    payload: [
                        'ticket_id' => $ticket->identifier(),
                        'from_target_user_id' => $gone->identifier(),
                        'notify_user_id' => $escalatorId,
                    ],
                );

                $ids[] = $ticket->identifier();
            }

            return $ids;
        });
    }
}
