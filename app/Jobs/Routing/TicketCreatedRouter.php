<?php

declare(strict_types=1);

namespace App\Jobs\Routing;

use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Jobs\OutboxConsumer;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-07 — DURABLE routing trigger for `ticket.created` (ADR 0011; contract fixture
 * fixtures/tickets/consumers/doc11.json). FR-07 "ticket created (Doc 06 all paths)".
 *
 * IDEMPOTENT ON event_id (C-09): the event names the TICKET; the pipeline re-reads the row
 * and routes only a ticket that is open-family AND unassigned (a redelivery after a
 * successful assignment finds it assigned → no-op, no second record). Runs on the
 * `automation` family (ADR 0007's precedent).
 */
final class TicketCreatedRouter extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(RoutingPipeline $pipeline): void
    {
        /** @var object{id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);
        $ticketId = $payload['ticket_id'] ?? null;
        if (! is_string($ticketId)) {
            return;
        }
        $ticket = Ticket::query()->find($ticketId);
        if (! $ticket instanceof Ticket || $ticket->assignee_id !== null || ! in_array((string) $ticket->status, ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'], true)) {
            return; // already assigned, or not open-family — nothing to route
        }
        $connection = DB::table('conversations')->where('id', $ticket->conversation_id)->value('channel_ref_id');
        $inputs = ['explicit_department_id' => is_string($ticket->department_id) ? $ticket->department_id : null, 'connection_id' => is_string($connection) ? $connection : null];
        $pipeline->route($ticket, 'created', $inputs);
    }
}
