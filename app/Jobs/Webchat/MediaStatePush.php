<?php

declare(strict_types=1);

namespace App\Jobs\Webchat;

use App\Domain\Webchat\Realtime\WebchatPusher;
use App\Domain\Webchat\WebchatAdapter;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-16 / SEC-04 / D15 (S13 step 9b): a scan verdict WAKES the visitor's
 * thread. Doc 04's pipeline publishes `media.stored` (clean) and `media.infected`
 * (blocked) through the outbox; this consumer pushes a tiny frame to the
 * visitor's private thread channel so the panel re-reads its cursor page and the
 * "Checking the file…" line becomes the thumbnail (or the blocked line). The
 * frame carries NO bytes, no storage key and no verdict detail: the cursor page
 * is the record, the socket is the wake-up (Doc 13 §10). Non-webchat
 * conversations are ignored (channel-agnostic — the lookup is by channel name
 * from the adapter contract, never a conditional in domain code).
 */
final class MediaStatePush extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'webchat-out';
    }

    public function handle(WebchatPusher $pusher): int
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null) {
            return 0;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);
        $messageId = $payload['message_id'] ?? null;
        $conversationId = $payload['conversation_id'] ?? null;
        if (! is_string($messageId) || ! is_string($conversationId)) {
            return 0;
        }
        $row = DB::table('conversations')->where('id', $conversationId)->where('channel', WebchatAdapter::CHANNEL)->first(['external_chat_id']);
        if ($row === null || ! is_string($row->external_chat_id)) {
            return 0;
        }
        $pusher->toVisitor($row->external_chat_id, WebchatPusher::SUFFIX_THREAD, 'webchat.media', [
            'message_ref' => $messageId,
            'index' => is_int($payload['index'] ?? null) ? $payload['index'] : 0,
            'state' => $event->name === 'media.stored' ? 'clean' : 'blocked',
        ]);

        return 1;
    }
}
