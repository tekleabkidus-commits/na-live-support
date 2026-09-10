<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use App\Domain\Sms\Catalog\SendableEvent;

/**
 * `ticket.resolved` → the `ticket_resolved` SMS (A-8: the transition service's
 * event doubles as the signal — no second event exists). Reads: tenant_id,
 * ticket_id, conversation_id, actor_type (`doc14.json` pins them).
 */
final class TicketResolvedSms extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['ticket.resolved']);
        if ($consumed === null || ! $this->toggleOn(SendableEvent::TicketResolved) || $this->alreadyProduced()) {
            return;
        }
        $ticketId = $consumed['payload']['ticket_id'];
        if (! is_string($ticketId)) {
            return;
        }

        $this->enqueueForTicket(SendableEvent::TicketResolved, $ticketId);
    }
}
