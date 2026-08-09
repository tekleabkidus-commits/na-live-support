<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Domain\Telegram\Window\WindowTracker;
use App\Jobs\OutboxConsumer;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * FR-22/FR-24 (ADR 0011 durable consumer): every customer message on a
 * telegram BUSINESS conversation (re)opens the reply window from that
 * message's DB-time receipt — the event-driven update that bypasses the sweep
 * cadence. When the window was EXPIRED, this is FR-24's reopen: → open,
 * recompute expiry, clear badges, and notify the open ticket's assignee.
 *
 * C-09 IDEMPOTENCY: keyed on nothing but the event's own message. The window
 * open is computed from the message's created_at (fixed), so reprocessing the
 * SAME event recomputes the SAME expiry and re-sets state open — a no-op the
 * second time. The FR-24 assignee notification is emitted ONLY on the
 * expired→open TRANSITION (guarded by the prior state read inside the same
 * statement), so a re-drive after a mid-flight death notifies exactly once.
 *
 * "Notifies the assignee through Doc 06's doors": the assignee is read from
 * the conversation's open_ticket_id → tickets.assignee_id (Doc 06 data); the
 * notification rides telegram.window.reopened (Doc 07 delivers). No ticket
 * transition is emitted — a window reopen changes the window, never the
 * ticket's status.
 */
final class WindowReopenConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(WindowTracker $tracker, EventPublisher $events): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();

        if ($event === null || $event->name !== 'message.received') {
            return;
        }

        /** @var array{message_id: string, conversation_id: string} $payload */
        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);

        $conversation = DB::table('conversations')
            ->where('id', $payload['conversation_id'])
            ->first(['id', 'channel', 'business_connection_id', 'reply_window_state', 'open_ticket_id']);

        // Only telegram BUSINESS conversations have a window.
        if ($conversation === null
            || $conversation->channel !== 'telegram'
            || ! is_string($conversation->business_connection_id)
            || $conversation->business_connection_id === '') {
            return;
        }

        // Only CUSTOMER inbound reopens the window (an owner-phone agent reply
        // does not — FR-23's "only customer activity reopens").
        $authorType = DB::table('messages')->where('id', $payload['message_id'])->value('author_type');
        if ($authorType !== 'customer') {
            return;
        }

        $wasExpired = $conversation->reply_window_state === 'expired';
        $conversationId = is_scalar($conversation->id) ? (string) $conversation->id : '';

        // Recompute + reopen from the message's DB-time receipt (idempotent).
        $expiry = $tracker->openFromInbound($conversationId, $payload['message_id']);

        // FR-24: notify the assignee ONLY on the expired→open transition.
        if ($wasExpired) {
            $assigneeId = null;
            if (is_string($conversation->open_ticket_id)) {
                $assigneeId = DB::table('tickets')->where('id', $conversation->open_ticket_id)->value('assignee_id');
            }

            $events->publish('telegram.window.reopened', [
                'tenant_id' => $this->tenantId(),
                'conversation_id' => $conversationId,
                'expires_at' => $expiry,
                'assignee_id' => is_string($assigneeId) ? $assigneeId : null,
            ]);
        }
    }

    private function tenantId(): string
    {
        return (string) app(\App\Support\Tenancy\TenantContext::class)->tenantId();
    }
}
