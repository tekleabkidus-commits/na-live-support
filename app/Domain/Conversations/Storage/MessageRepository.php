<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Storage;

use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Http\CursorPage;
use App\Support\Http\CursorPagination;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY path to the partitioned messages table (Doc 04 T-01, Doc 01 R-05: no
 * ad-hoc Eloquent on partitions — a structural test greps for strays).
 *
 * Ordering truth lives here: thread pages are (created_at, id) — receipt time plus
 * UUIDv7 tiebreak (FR-07/BR-04, C-11). Channel timestamps land in
 * channel_occurred_at, metadata only.
 */
final class MessageRepository
{
    /**
     * Persist one normalized inbound message (FR-10 step 4's row half — the caller
     * owns the transaction that also writes the outbox event). Media items become
     * PLACEHOLDERS (scan_status pending) for the media jobs (step 5).
     */
    public function persistInbound(
        Conversation $conversation,
        NormalizedInbound $inbound,
        string $customerId,
        bool $quarantined = false,
    ): Message {
        return Message::query()->create([
            'conversation_id' => $conversation->identifier(),
            'direction' => 'in',
            'author_type' => 'customer',
            'author_id' => $customerId,
            'kind' => $inbound->kind->value,
            'body' => $inbound->body,
            'media' => array_map(
                static fn ($item): array => [
                    'kind' => $item->kind->value,
                    'source_url' => $item->sourceUrl,
                    'mime' => $item->mime,
                    'bytes' => $item->bytes,
                    'width' => $item->width,
                    'height' => $item->height,
                    'duration_seconds' => $item->durationSeconds,
                    'storage_key' => null,
                    'thumb_key' => null,
                    'transcript_ref' => null,
                    'ocr_ref' => null,
                    'scan_status' => 'pending',
                ],
                $inbound->media,
            ),
            'external_message_id' => $inbound->externalMessageId,
            'quarantined' => $quarantined,
            'channel_meta' => $inbound->channelMeta,
            'channel_occurred_at' => $inbound->occurredAt,
        ]);
    }

    /**
     * Persist one outbound message in `queued` (FR-12) — every compose source (agent,
     * AI, automation, system) starts here; the outbox job is the caller's half of the
     * same transaction.
     */
    public function persistOutbound(
        Conversation $conversation,
        string $authorType,
        ?string $authorId,
        string $kind,
        ?string $body,
        ?string $idempotencyKey = null,
    ): Message {
        return Message::query()->create([
            'conversation_id' => $conversation->identifier(),
            'direction' => 'out',
            'author_type' => $authorType,
            'author_id' => $authorId,
            'kind' => $kind,
            'body' => $body,
            'delivery_state' => 'queued',
            'client_idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * A prior outbound row for this idempotency key, if one exists (T-03: retries
     * must find the original, never write a sibling).
     */
    public function findByIdempotencyKey(Conversation $conversation, string $key): ?Message
    {
        return Message::query()
            ->where('conversation_id', $conversation->identifier())
            ->where('client_idempotency_key', $key)
            ->first();
    }

    /**
     * One thread page in display order (FR-07; §12's cursor-by-(created_at,id)).
     * 50/page is the §12 profile; C-05's ceiling still governs.
     *
     * @return CursorPage<\stdClass>
     */
    public function threadPage(Conversation $conversation, CursorPagination $pagination): CursorPage
    {
        return $pagination->paginateByTimestampAndId(
            DB::table('messages')
                ->where('tenant_id', $conversation->getAttribute('tenant_id'))
                ->where('conversation_id', $conversation->identifier())
                ->select(['id', 'created_at', 'direction', 'author_type', 'kind', 'body', 'media', 'delivery_state', 'edited_at', 'deleted_at', 'channel_occurred_at']),
            'created_at',
        );
    }

    /** Address one row in this conversation (partition-safe: id + conversation scope). */
    public function findInConversation(Conversation $conversation, string $messageId): ?Message
    {
        return Message::query()
            ->where('conversation_id', $conversation->identifier())
            ->whereKey($messageId)
            ->first();
    }
}
