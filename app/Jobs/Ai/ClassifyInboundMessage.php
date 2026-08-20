<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\Pipeline\InboundClassification;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09's FR-10-step-6 SUBSCRIBER (the Doc 04 fan-out contract): classify
 * the inbound burst AFTER persistence — a durable ADR 0011 consumer on
 * message.received, ai queue (a customer may be waiting on the turn this
 * classification routes).
 *
 * IDEMPOTENT ON event_id (C-09) two ways: a redelivery re-reads the event
 * and finds its message already carries a decision for this event's
 * message (the message_id-keyed existence check below), and the batch-tail
 * debounce makes a duplicate of a superseded arrival a no-op by
 * construction.
 */
final class ClassifyInboundMessage extends OutboxConsumer
{
    public function handle(InboundClassification $classification): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null || $event->name !== 'message.received') {
            return;
        }

        $payload = json_decode($event->payload, true);
        $messageId = is_array($payload) && is_string($payload['message_id'] ?? null) ? $payload['message_id'] : null;
        if ($messageId === null) {
            return;
        }

        // C-09: a redelivered event whose message already produced a
        // classification decision changes nothing.
        $already = DB::table('ai_decisions')
            ->where('message_id', $messageId)
            ->where('task', 'classify_message')
            ->exists();
        if ($already) {
            return;
        }

        $classification->classify($messageId);
    }

    protected function queueFamily(): string
    {
        return 'ai';
    }
}
