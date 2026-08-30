<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Placement;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 T-02 / A-49 — THE ONE WRITER OF tickets.queue_id (S11 STOP-A packet,
 * countersigned 2026-08-30; Doc 06's tree by design — routing never writes ticket rows).
 *
 * `queued_at` is NOT written here: the migrator-owned tickets_stamp_placement() trigger
 * (SCHEMA AMENDMENT 5 FLAG G) stamps it from the database clock when queue_id changes on
 * an unassigned ticket, and `routed_at` when an assignee arrives — the app cannot name
 * either column (R-19a). PlaceInQueueOneDoorTest is the static sweep with a positive
 * control: no other file under app/ writes queue_id.
 *
 * actor null = routing (system); a User = a staff queue change (Doc 06 FR-22's queue
 * half, called by TransferTicket when a transfer names a queue).
 */
final class PlaceInQueue
{
    public function __construct(private readonly EventPublisher $events) {}

    public function place(Ticket $ticket, string $queueId, ?User $actor, string $reason): Ticket
    {
        return DB::transaction(function () use ($ticket, $queueId, $actor, $reason): Ticket {
            Ticket::query()->whereKey($ticket->identifier())->update([
                'queue_id' => $queueId,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();

            // FR-19: "ticket.waiting {queue, position}" — position null = placed, not yet
            // ranked; the dispatcher's ZSET rank follows in the same family (T-03).
            $this->events->publish('ticket.waiting', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'queue' => $queueId,
                'position' => null,
            ]);

            // The reason and the actor travel on the routing record / the transfer audit,
            // never as a second audit action here (one act, one entry).
            unset($actor, $reason);

            return $ticket;
        });
    }
}
