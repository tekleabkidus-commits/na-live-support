<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use App\Domain\Sms\Catalog\SendableEvent;

/**
 * `ticket.closed` → the `ticket_closed` SMS — OFF by default (§21). Reads:
 * tenant_id, ticket_id, conversation_id, actor_type (the ordinary close is
 * the auto-close sweep, actor_type=system — sent all the same when enabled).
 */
final class TicketClosedSms extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['ticket.closed']);
        if ($consumed === null || ! $this->toggleOn(SendableEvent::TicketClosed) || $this->alreadyProduced()) {
            return;
        }
        $ticketId = $consumed['payload']['ticket_id'];
        if (! is_string($ticketId)) {
            return;
        }

        $this->enqueueForTicket(SendableEvent::TicketClosed, $ticketId);
    }
}
