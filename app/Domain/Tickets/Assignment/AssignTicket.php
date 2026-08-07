<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

use App\Domain\Tickets\Storage\TicketScope;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Settings\SettingsRepository;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-15 assignment, FR-16's claim race, and FR-05's implicit claim.
 *
 * THE RACE IS WON BY THE DATABASE, not by a read (FR-16: "first commit wins"). A claim
 * is `UPDATE … WHERE id = ? AND assignee_id IS NULL`: two agents pressing the same
 * keystroke both pass any check you like, and exactly one of them matches a row. The
 * loser is handed `already_claimed {by}` with the winner's id so the UI can swap an
 * avatar in place rather than raise a modal (US-01).
 *
 * FR-05 RIDES ALONG: "new → open occurs on first assignment (auto or manual) OR first
 * staff public reply". So a successful assignment of a `new` ticket transitions it —
 * through TicketTransitionService, never by writing status here (T-01/NFR-03). The
 * transition is deliberately AFTER the assignment write and inside the same
 * transaction: if the status change were first, a lost claim race would leave a ticket
 * `open` with no assignee.
 *
 * SCOPE IS CHECKED ON THE TARGET (FR-15), never on the assigner. Assigning work to
 * someone who cannot see it is how a ticket becomes nobody's while looking owned.
 */
final class AssignTicket
{
    public function __construct(
        private readonly TicketScope $scope,
        private readonly TicketTransitionService $transitions,
        private readonly CapabilityMapProvider $verdicts,
        private readonly SettingsRepository $settings,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** FR-15's "others" door — `tickets.assign`. */
    public function assign(Ticket $ticket, User $assignee, User $actor): Ticket
    {
        $this->requirePermission($actor, 'tickets.assign');

        return $this->write($ticket, $assignee, $actor, claimOnly: false);
    }

    /** FR-15's claim door — `tickets.assign_self`. FR-16's race lives here. */
    public function claim(Ticket $ticket, User $actor): Ticket
    {
        $this->requirePermission($actor, 'tickets.assign_self');

        return $this->write($ticket, $actor, $actor, claimOnly: true);
    }

    /**
     * FR-15: "unassign returns to queue (Doc 11)".
     *
     * The re-route itself is Doc 11's — this emits the event it will consume and
     * leaves the ticket assignee-less, which is what "in the queue" means until a
     * router exists. EC-06's unassign-on-deactivation is the same seam.
     */
    public function unassign(Ticket $ticket, User $actor): Ticket
    {
        $this->requirePermission($actor, 'tickets.assign');

        $previous = $ticket->assignee_id;

        return DB::transaction(function () use ($ticket, $actor, $previous): Ticket {
            Ticket::query()->whereKey($ticket->identifier())->update([
                'assignee_id' => null,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();

            $this->record($ticket, $previous, null, $actor);

            return $ticket;
        });
    }

    private function write(Ticket $ticket, User $assignee, User $actor, bool $claimOnly): Ticket
    {
        // FR-15's intersection, on the ASSIGNEE — and asked as "would they see it
        // ONCE ASSIGNED", not "do they see it now". The present-tense question is
        // circular for an Own-scoped agent: an unassigned queue ticket is nobody's, so
        // nobody could ever claim one. See TicketScope::visibleOnceAssignedTo().
        if (! $this->verdicts->verdictFor($assignee, 'tickets.view')->allowed
            || ! $this->scope->visibleOnceAssignedTo($assignee, $ticket)) {
            throw new AssigneeOutOfScope($assignee->identifier());
        }

        $previous = $ticket->assignee_id;

        return DB::transaction(function () use ($ticket, $assignee, $actor, $claimOnly, $previous): Ticket {
            $query = Ticket::query()->whereKey($ticket->identifier());

            if ($claimOnly) {
                // FR-16: the guard IS the race. Only an unclaimed ticket matches.
                $query->whereNull('assignee_id');
            }

            $written = $query->update([
                'assignee_id' => $assignee->identifier(),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            if ($written !== 1) {
                $current = Ticket::query()->whereKey($ticket->identifier())->first();
                $by = $current?->assignee_id;

                if (is_string($by) && $by === $assignee->identifier()) {
                    // The same person claiming twice is not a race — it is a
                    // double-click, and EC-07's posture says that is a success.
                    $ticket->refresh();

                    return $ticket;
                }

                throw new AlreadyClaimed($ticket->identifier(), is_string($by) ? $by : 'unknown');
            }

            $ticket->refresh();
            $this->record($ticket, $previous, $assignee->identifier(), $actor);

            // FR-05: first assignment opens a `new` ticket. Through the transition
            // service — the only writer — so the FR-24 stamps, the event and the
            // FR-10 invariant all still happen.
            if ($ticket->status() === TicketStatus::New) {
                $this->transitions->transition($ticket, TicketStatus::Open, TransitionContext::staff($actor));
                $ticket->refresh();
            }

            return $ticket;
        });
    }

    /**
     * FR-05/BR-03's implicit claim: "a reply IS a claim". Called by the reply path
     * (step 7) when a staff member sends the first public reply on an unassigned
     * ticket, and gated by the per-tenant `tickets.implicit_claim` setting (R-04's
     * toggle, for strict-dispatch teams).
     *
     * Silent by design when the toggle is off or the ticket is already assigned: this
     * is a side effect of replying, and a reply must not fail because a claim could
     * not be made.
     */
    public function implicitClaimOnReply(Ticket $ticket, User $replier): bool
    {
        if (! $this->settings->get('tickets.implicit_claim')) {
            return false;
        }

        if ($ticket->assignee_id !== null) {
            return false;
        }

        if (! $this->verdicts->verdictFor($replier, 'tickets.assign_self')->allowed) {
            return false;
        }

        try {
            $this->write($ticket, $replier, $replier, claimOnly: true);
        } catch (AlreadyClaimed|AssigneeOutOfScope) {
            // Someone else got there first, or the replier cannot hold it. Either way
            // the REPLY stands; only the claim did not.
            return false;
        }

        return true;
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! $this->verdicts->verdictFor($actor, $permission)->allowed) {
            throw new DomainException(sprintf('%s is required (FR-15).', $permission));
        }
    }

    private function record(Ticket $ticket, ?string $old, ?string $new, User $actor): void
    {
        // FR-15: "Every change emits ticket.assigned {old, new, by}."
        $this->events->publish('ticket.assigned', [
            'tenant_id' => $ticket->getAttribute('tenant_id'),
            'ticket_id' => $ticket->identifier(),
            'conversation_id' => $ticket->conversation_id,
            'old_assignee_id' => $old,
            'new_assignee_id' => $new,
            'by' => $actor->identifier(),
        ]);

        $this->audit->record(
            actionKey: 'ticket.assigned',
            entity: 'ticket',
            entityId: $ticket->identifier(),
            actorType: AuditActor::Staff,
            payload: [
                'ticket_id' => $ticket->identifier(),
                'old_assignee_id' => $old,
                'new_assignee_id' => $new,
            ],
            actorId: $actor->identifier(),
        );
    }
}
