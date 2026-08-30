<?php

declare(strict_types=1);

namespace App\Jobs\Routing;

use App\Domain\Routing\Reopen\CourtesyHold;
use App\Domain\Routing\Rescue\HeartbeatRescue;
use App\Domain\Routing\Rescue\OrphanRescue;
use App\Jobs\OutboxConsumer;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-16 / EC-06 / EC-08 — the rescue family's DURABLE consumer (ADR 0011: a registry
 * entry, never a relay edit): `agent.status_changed` arms/disarms the heartbeat-rescue timer,
 * `org.queue.deleted` runs the orphan path, `ticket.reopened` writes the courtesy hold.
 * Idempotent by re-check: every action reads truth from the rows (C-09).
 */
final class RescueConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(HeartbeatRescue $heartbeat, OrphanRescue $orphan, CourtesyHold $hold): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);
        switch ($event->name) {
            case 'agent.status_changed':
                $userId = $payload['user_id'] ?? null;
                $status = $payload['status'] ?? null;
                if (is_string($userId) && is_string($status)) {
                    $reason = $payload['reason'] ?? null;
                    $heartbeat->onStatusChanged($userId, $status, is_string($reason) ? $reason : null);
                }
                break;
            case 'org.queue.deleted':
                $queueId = $payload['queue_id'] ?? null;
                if (is_string($queueId)) {
                    $orphan->onQueueDeleted($queueId);
                }
                break;
            case 'ticket.reopened':
                $ticketId = $payload['ticket_id'] ?? null;
                $ticket = is_string($ticketId) ? Ticket::query()->find($ticketId) : null;
                if ($ticket instanceof Ticket && $ticket->assignee_id !== null && DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'reopened')->where('outcome', 'waiting')->whereRaw("snapshot->>'hold_for' = ?", [$ticket->assignee_id])->whereRaw("(snapshot->>'hold_until')::bigint > EXTRACT(EPOCH FROM clock_timestamp())")->doesntExist()) {
                    $hold->onReopened($ticket);
                }
                break;
        }
    }
}
