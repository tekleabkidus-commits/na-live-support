<?php

declare(strict_types=1);

namespace App\Jobs\Tickets;

use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Reopen\ReopenTicket;
use App\Jobs\OutboxConsumer;
use App\Models\Conversation;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0011's first durable consumer: message.received → FR-08.
 *
 * "A customer reply must reopen its ticket even if a worker dies mid-flight" — so this
 * runs on a queue (durability from dispatch), carries ONLY the event id, and re-reads
 * everything else under its own tenant context. FS-02's catch-up is the queue's
 * re-drive: die between persist and reopen and the attempt's transaction rolled back
 * whole, so the re-driven run finds the world exactly as the first one did.
 *
 * IDEMPOTENCY ON event_id (C-09), BY STATE AND BY KEY — not by a processed-events
 * ledger:
 *
 *   reopen half     keyed off CURRENT state: a second delivery finds the conversation
 *                   holding an open-family ticket (the one the first delivery
 *                   reopened) and does nothing. A staff transition racing us is the
 *                   same read, same answer.
 *   successor half  creation is NOT naturally idempotent — two deliveries would mint
 *                   two tickets — so it rides FR-13's idempotency key, derived from
 *                   the event id. The winner's row is every redelivery's answer.
 *
 * THE DECISION LADDER (all Doc 06):
 *   1. open-family ticket exists → nothing to do (the message lands in it; FR-10)
 *   2. latest resolved ticket → reopen unconditionally (FR-10/BR-05: reopen beats
 *      duplicate) — this is also FR-07's cancel path: leaving `resolved` disarms the
 *      auto-close timer in the transition's own transaction (EC-01's other half)
 *   3. latest closed ticket → within EC-02's window: reopen; beyond: successor with
 *      previous_ticket_id (FR-08)
 *   4. latest ticket is TERMINAL spam (spam_marked_at + 7 d passed) → a FRESH ticket
 *      (EC-09: "the customer's next message opens a fresh ticket — spam never blocks
 *      the conversation"), unlinked and source=automation (A-17); while the spam is
 *      still UNMARKABLE the message just lands — minting a ticket there would
 *      collide with the unmark's restore (FR-10)
 *   5. nothing eligible (no tickets, or only unmarkable spam) → nothing: ticket
 *      CREATION on plain inbound traffic is Doc 09's handoff decision, never
 *      automatic here
 */
final class TicketReopenConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(ReopenTicket $reopen, CreateTicket $create): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();

        if ($event === null || $event->name !== 'message.received') {
            return;
        }

        /** @var array{message_id: string, conversation_id: string} $payload */
        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()->find($payload['conversation_id']);

        if ($conversation === null || $conversation->open_ticket_id !== null) {
            return;
        }

        $resolved = $this->latest($conversation, 'resolved', 'resolved_at');

        if ($resolved instanceof Ticket) {
            $reopen->reopenFromCustomerMessage($resolved, $payload['message_id']);

            return;
        }

        $closed = $this->latest($conversation, 'closed', 'closed_at');

        if (! $closed instanceof Ticket || $closed->closed_at === null) {
            $this->freshTicketAfterTerminalSpam($conversation, $create);

            return;
        }

        if ($reopen->reopenFromCustomerMessage($closed, $payload['message_id']) !== null) {
            return;
        }

        // Beyond the window: "new ticket in the same conversation carrying a
        // previous_ticket_id link" (FR-08). Source is `automation` per filed
        // interpretation A-12 — FR-01's enum has no system/customer value, and this
        // door is a codified rule acting on its own, which is what that value names.
        // The successor starts unassigned; routing it is Doc 11's job from the
        // ticket.created event.
        $create->create(new NewTicket(
            conversation: $conversation,
            source: TicketSource::Automation,
            previousTicketId: $closed->identifier(),
            idempotencyKey: 'fr08:'.$this->eventId,
        ));
    }

    /**
     * EC-09's fresh ticket (A-17): the conversation's thread ended in TERMINAL spam —
     * past its 7-day unmark window, in DB time — and the customer wrote anyway. "Spam
     * never blocks the conversation unless block was chosen", so the message opens a
     * FRESH ticket: UNLINKED (no previous_ticket_id — the spam row is excluded
     * traffic, and threading excluded history into a live ticket would smuggle it
     * back into view), source=automation (A-12's reasoning verbatim), idempotent on
     * the event id (C-09 — creation is not naturally idempotent).
     *
     * While the spam is still UNMARKABLE, deliberately nothing: a staff unmark
     * restores the prior ticket into the open slot (FR-10), and a ticket minted here
     * would collide with that restore.
     */
    private function freshTicketAfterTerminalSpam(Conversation $conversation, CreateTicket $create): void
    {
        /** @var object{id: string}|null $terminal */
        $terminal = DB::selectOne(
            "SELECT id FROM tickets
              WHERE conversation_id = ?
                AND status = 'spam'
                AND linked_work = FALSE
                AND merged_into IS NULL
                AND spam_marked_at IS NOT NULL
                AND clock_timestamp() > spam_marked_at + make_interval(days => 7)
              ORDER BY spam_marked_at DESC, id DESC
              LIMIT 1",
            [$conversation->identifier()],
        );

        if ($terminal === null) {
            return;
        }

        $create->create(new NewTicket(
            conversation: $conversation,
            source: TicketSource::Automation,
            idempotencyKey: 'ec09:'.$this->eventId,
        ));
    }

    /**
     * The reopen candidate: the most recent NON-LINKED, NON-MERGED ticket in the given
     * state, by its own transition stamp (DB time, C-11; id as the same-microsecond
     * tiebreak — the R-23 family principle). linked_work rows are FR-21's parallel
     * track and never the conversation's primary thread; merged-away rows are frozen
     * behind their permanent banner (A-14) — a reply lands as a message and the
     * surviving ticket is where the follow-up belongs.
     */
    private function latest(Conversation $conversation, string $status, string $stampColumn): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->where('conversation_id', $conversation->identifier())
            ->where('status', $status)
            ->where('linked_work', false)
            ->whereNull('merged_into')
            ->orderByDesc($stampColumn)
            ->orderByDesc('id')
            ->first();

        return $ticket;
    }
}
