<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use App\Domain\Sms\Catalog\SendableEvent;

/**
 * `ticket.assigned` → the `ticket_assigned` SMS — OFF by default (§21); the
 * toggle-first check means nothing exists until a tenant turns it on.
 * Reads: tenant_id, ticket_id, conversation_id.
 */
final class TicketAssignedSms extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['ticket.assigned']);
        if ($consumed === null || ! $this->toggleOn(SendableEvent::TicketAssigned) || $this->alreadyProduced()) {
            return;
        }
        $ticketId = $consumed['payload']['ticket_id'];
        if (! is_string($ticketId)) {
            return;
        }

        $this->enqueueForTicket(SendableEvent::TicketAssigned, $ticketId);
    }
}
