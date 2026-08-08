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
 *   4. nothing eligible (no tickets, or only spam — EC-09's fresh-ticket semantics
 *      are step 7's, with the spam disposition) → nothing: ticket CREATION on plain
 *      inbound traffic is Doc 09's handoff decision, never automatic here
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
     * The reopen candidate: the most recent NON-LINKED ticket in the given state, by
     * its own transition stamp (DB time, C-11; id as the same-microsecond tiebreak —
     * the R-23 family principle). linked_work rows are FR-21's parallel track and
     * never the conversation's primary thread.
     */
    private function latest(Conversation $conversation, string $status, string $stampColumn): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->where('conversation_id', $conversation->identifier())
            ->where('status', $status)
            ->where('linked_work', false)
            ->orderByDesc($stampColumn)
            ->orderByDesc('id')
            ->first();

        return $ticket;
    }
}
