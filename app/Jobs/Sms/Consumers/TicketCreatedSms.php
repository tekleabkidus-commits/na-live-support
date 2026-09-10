<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use App\Domain\Sms\Catalog\SendableEvent;

/**
 * `ticket.created` → `ticket_created`, or `offline_form_confirmation` when the
 * CLOSED payload's `source = 'offline_form'` (step-0 finding 5: the offline
 * marker is this field plus the ticket tag — never `channel_meta` on the
 * ticket; the widget's receipt-SMS promise becomes true here). Reads:
 * tenant_id, ticket_id, conversation_id, customer_id (cross-check only —
 * SEC-07's truth re-fetch decides), source.
 */
final class TicketCreatedSms extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['ticket.created']);
        if ($consumed === null) {
            return;
        }
        $event = ($consumed['payload']['source'] ?? null) === 'offline_form'
            ? SendableEvent::OfflineFormConfirmation
            : SendableEvent::TicketCreated;
        if (! $this->toggleOn($event) || $this->alreadyProduced()) {
            return;
        }
        $ticketId = $consumed['payload']['ticket_id'];
        $payloadCustomerId = $consumed['payload']['customer_id'] ?? null;
        if (! is_string($ticketId)) {
            return;
        }

        $this->enqueueForTicket($event, $ticketId, is_string($payloadCustomerId) ? $payloadCustomerId : null);
    }
}
