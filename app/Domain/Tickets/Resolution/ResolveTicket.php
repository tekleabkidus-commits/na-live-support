<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Resolution;

use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Domain\Tickets\Transitions\TransitionResult;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * FR-06's resolve door: "resolved requires either a public staff/AI reply in the
 * ticket or an explicit resolution note (setting tickets.resolve.requires_reply
 * default on)".
 *
 * BR-04 names the reason: "no silent resolves; the customer always received
 * something" — or, failing that, the ticket at least records what the resolution WAS.
 *
 * WHERE THE SUBSTANCE CHECK LIVES, AND WHY NOT IN THE SERVICE. The transition service
 * is the state MACHINE: legality, permission, invariants that hold for every door.
 * FR-06's requirement is about what this OPERATION brings with it — a note is an
 * argument to resolve(), and the machine cannot check an argument it never sees. So
 * every resolve door (Doc 07's UI, step 7's bulk `resolve`, Doc 12's automation) goes
 * through this class, the same one-path posture as CreateTicket.
 *
 * THE REPLY HALF IS READ OFF first_response_at, not re-derived: FR-24 defines that
 * column as "first public staff or AI message after ticket creation" — precisely
 * FR-06's condition — and the R-20 trigger stamped it under rules the app cannot
 * bend (notes refused, pre-ticket refused, cross-conversation refused). Re-querying
 * messages here would be a second, driftable definition of the same fact.
 *
 * WHAT RESOLUTION TRIGGERS. FR-06's "triggers CSAT flow (Doc 12) and SMS event
 * ticket_resolved (Doc 14)" is the ticket.resolved event the transition service
 * publishes on every entry into `resolved` (A-8: it doubles as Doc 14's signal).
 * Neither consumer exists yet; the signal they will consume does.
 */
final class ResolveTicket
{
    public function __construct(
        private readonly TicketTransitionService $transitions,
        private readonly NotesWriter $notes,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Resolve with substance. The note, when given, is written FIRST and in the same
     * transaction: a resolve that fails cannot strand its note, and a note that fails
     * (Doc 04's rules) cannot leave a substance-free resolve behind.
     */
    public function resolve(Ticket $ticket, User $actor, ?string $resolutionNote = null): TransitionResult
    {
        $note = $resolutionNote !== null && trim($resolutionNote) !== '' ? trim($resolutionNote) : null;

        if ($this->settings->get('tickets.resolve.requires_reply') === true
            && $ticket->first_response_at === null
            && $note === null
        ) {
            throw new TransitionRefused(
                'resolution_requires_substance',
                'the customer has received no public reply on this ticket; resolve with a reply or an explicit resolution note (FR-06/BR-04).',
            );
        }

        return DB::transaction(function () use ($ticket, $actor, $note): TransitionResult {
            if ($note !== null) {
                /** @var Conversation $conversation */
                $conversation = Conversation::query()->findOrFail($ticket->conversation_id);
                $this->notes->addNote($conversation, $actor, $note);
            }

            return $this->transitions->transition(
                $ticket,
                TicketStatus::Resolved,
                TransitionContext::staff($actor),
            );
        });
    }
}
