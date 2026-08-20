<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Jobs\OutboxConsumer;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * The shared shape of Doc 09's media-hook consumers (FR-05 over Doc 04
 * T-05): event -> message -> media item, with the C-09 already-processed
 * check on the item's own ref key.
 */
abstract class MediaHookConsumer extends OutboxConsumer
{
    /** @return array{0: Message, 1: int, 2: array<string, mixed>}|null */
    protected function mediaContext(string $expectedEvent): ?array
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null || $event->name !== $expectedEvent) {
            return null;
        }

        $payload = json_decode($event->payload, true);
        if (! is_array($payload) || ! is_string($payload['message_id'] ?? null) || ! is_numeric($payload['index'] ?? null)) {
            return null;
        }

        $message = Message::query()->find($payload['message_id']);
        if ($message === null) {
            return null;
        }

        $index = (int) $payload['index'];
        $media = $message->getAttribute('media');
        $item = is_array($media) && is_array($media[$index] ?? null) ? $media[$index] : null;
        if ($item === null) {
            return null;
        }

        // C-09: already processed — a redelivery changes nothing.
        $refKey = $expectedEvent === 'media.needs_transcript' ? 'transcript_ref' : 'ocr_ref';
        if (($item[$refKey] ?? null) !== null) {
            return null;
        }

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = [];
        foreach ($item as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }
        $stringKeyed['storage_key'] = $payload['storage_key'] ?? null;

        return [$message, $index, $stringKeyed];
    }

    protected function queueFamily(): string
    {
        return 'ai';
    }
}
