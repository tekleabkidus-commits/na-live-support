<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use Illuminate\Support\Facades\DB;

/**
 * The projector's window onto the CANONICAL rows — reads only, returned raw.
 *
 * THIS SPLIT IS THE ticket-status-writer GATE'S OWN DESIGN CONSTRAINT,
 * followed rather than argued with: a file that assigns a `status` array key
 * must not also reference the ticket model or table ("that state belongs in
 * its own repository" — the gate's docblock). So THIS file holds every
 * tickets-table read and assigns nothing; TicketListItems holds the copy
 * assignment into the READ MODEL's own row and references no ticket context.
 * Between them, tickets.status is read here and written nowhere in Doc 07 —
 * which is exactly NFR-03's line.
 *
 * @phpstan-type TicketTruth object{
 *     ticket: object{id: string, tenant_id: string, conversation_id: string, customer_id: string|null, number: int|string, status: string, priority: string, pending_reason: string|null, source: string, assignee_id: string|null, department_id: string|null, channel_unreachable: bool, subject: string|null, created_at: string},
 *     conversation: object{channel: string, reply_window_expires_at: string|null}|null,
 *     customer: object{display_name: string|null}|null,
 *     lastPublic: object{kind: string, body: string|null, created_at: string}|null,
 *     lastActivityAt: string
 * }
 */
final class CanonicalTicketReader
{
    /** @return TicketTruth|null */
    public function read(string $ticketId): ?object
    {
        /** @var object{id: string, tenant_id: string, conversation_id: string, customer_id: string|null, number: int|string, status: string, priority: string, pending_reason: string|null, source: string, assignee_id: string|null, department_id: string|null, channel_unreachable: bool, subject: string|null, created_at: string}|null $ticket */
        $ticket = DB::table('tickets')->where('id', $ticketId)->first();
        if ($ticket === null) {
            return null;
        }

        /** @var object{channel: string, reply_window_expires_at: string|null}|null $conversation */
        $conversation = DB::table('conversations')->where('id', $ticket->conversation_id)->first();

        /** @var object{display_name: string|null}|null $customer */
        $customer = $ticket->customer_id !== null
            ? DB::table('customers')->where('id', $ticket->customer_id)->first()
            : null;

        /** @var object{kind: string, body: string|null, created_at: string}|null $lastPublic */
        $lastPublic = DB::table('messages')
            ->where('conversation_id', $ticket->conversation_id)
            ->whereNotIn('kind', ['note', 'system'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->select(['kind', 'body', 'created_at'])
            ->first();

        // LAST ACTIVITY, defined once (SystemViews' docblock): greatest of the
        // ticket's last status change and the conversation's last traffic —
        // all DB-time stamps (R-19b pinned-provenance source).
        /** @var object{last_activity: string}|null $activity */
        $activity = DB::table('tickets as t')
            ->join('conversations as c', 'c.id', '=', 't.conversation_id')
            ->where('t.id', $ticketId)
            ->selectRaw('GREATEST(t.last_status_changed_at, COALESCE(c.last_customer_message_at, t.created_at), COALESCE(c.last_outbound_at, t.created_at)) AS last_activity')
            ->first();

        return (object) [
            'ticket' => $ticket,
            'conversation' => $conversation,
            'customer' => $customer,
            'lastPublic' => $lastPublic,
            'lastActivityAt' => $activity->last_activity ?? $ticket->created_at,
        ];
    }
}
