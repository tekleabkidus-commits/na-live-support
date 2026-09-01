<?php

declare(strict_types=1);

namespace App\Jobs\Sla;

use App\Domain\Sla\Instances\InstanceArmer;
use App\Domain\Sla\Instances\StageLifecycle;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-09/FR-10 (ADR 0011) — THE durable SLA lifecycle consumer: arming
 * on creation, next_response on customer messages (EC-02's idempotent re-arm),
 * clears on staff public replies, pauses/reopen-re-arm on status flips,
 * retarget on priority flips (EC-03), the unreachable pause on the window
 * events. Carries ONLY the event id; every fact re-read from rows (the
 * RollupConsumer posture); idempotent per handler (live-row uniques, open-pause
 * checks, the instance unique).
 */
final class SlaLifecycleConsumer extends OutboxConsumer
{
    public function handle(InstanceArmer $armer, StageLifecycle $stages): void
    {
        /** @var object{name: string, payload: string, occurred_at: string}|null $event */
        $event = DB::table('outbox')->where('event_id', $this->eventId)->first(['name', 'payload', 'occurred_at']);
        if ($event === null) {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);
        $occurredAt = $event->occurred_at;

        switch ($event->name) {
            case 'ticket.created':
                if (is_string($payload['ticket_id'] ?? null)) {
                    $armer->armForTicket($payload['ticket_id'], $this->eventId);
                }
                break;
            case 'message.received':
                // message.received IS customer inbound (Doc 04's edge publishes it
                // for customer traffic only); the customer_id key confirms.
                if (is_string($payload['customer_id'] ?? null)) {
                    $ticketId = $this->openTicketOf($payload['conversation_id'] ?? null);
                    if ($ticketId !== null) {
                        $stages->onCustomerMessage($ticketId, $occurredAt);
                    }
                }
                break;
            case 'message.sent':
                $ticketId = $this->openTicketOf($payload['conversation_id'] ?? null);
                if ($ticketId !== null && $this->isPublicStaffMessage($payload['message_id'] ?? null)) {
                    $stages->onStaffReply($ticketId);
                }
                break;
            case 'ticket.status_changed':
                if (is_string($payload['ticket_id'] ?? null) && is_string($payload['old_status'] ?? null) && is_string($payload['new_status'] ?? null)) {
                    $stages->onStatusChanged($payload['ticket_id'], $payload['old_status'], $payload['new_status'], $occurredAt);
                }
                break;
            case 'ticket.priority_changed':
                if (is_string($payload['ticket_id'] ?? null) && is_string($payload['new_priority'] ?? null)) {
                    $stages->onPriorityChanged($payload['ticket_id'], $payload['new_priority']);
                }
                break;
            case 'telegram.window.expired':
                if (is_string($payload['conversation_id'] ?? null)) {
                    $stages->onWindowEvent($payload['conversation_id'], true, $occurredAt);
                }
                break;
            case 'telegram.window.reopened':
                if (is_string($payload['conversation_id'] ?? null)) {
                    $stages->onWindowEvent($payload['conversation_id'], false, $occurredAt);
                }
                break;
        }
    }

    protected function queueFamily(): string
    {
        return 'automation';
    }

    private function openTicketOf(mixed $conversationId): ?string
    {
        if (! is_string($conversationId)) {
            return null;
        }
        $ticketId = DB::table('tickets')->where('conversation_id', $conversationId)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->orderByDesc('created_at')->value('id');

        return is_string($ticketId) ? $ticketId : null;
    }

    private function isPublicStaffMessage(mixed $messageId): bool
    {
        if (! is_string($messageId)) {
            return false;
        }
        /** @var object{is_internal: bool, author_type: string}|null $message */
        $message = DB::table('messages')->where('id', $messageId)->first(['is_internal', 'author_type']);

        return $message !== null && ! $message->is_internal && in_array($message->author_type, ['staff', 'ai'], true);
    }
}
