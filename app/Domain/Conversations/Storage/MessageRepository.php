<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Storage;

use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Messages\LanguageDetector;
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
        ?\App\Domain\Conversations\Inbound\InboundAuthor $author = null,
    ): Message {
        return Message::query()->create([
            'conversation_id' => $conversation->identifier(),
            'direction' => 'in',
            // A-27: an inbound is the CUSTOMER's unless a channel resolver
            // proved otherwise server-side (owner-phone, T-03). The default
            // is unchanged, so non-owner inbound is byte-identical.
            'author_type' => $author !== null ? $author->authorType : 'customer',
            'author_id' => $author !== null ? $author->authorId : $customerId,
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
                    // §13: the stored alt slot every image needs, staff-editable
                    // (setMediaAltText) and reusable by the KB. The slot and its
                    // writer are Doc 04's; the editing SURFACE is Doc 07's.
                    'alt_text' => null,
                    'scan_status' => 'pending',
                ],
                $inbound->media,
            ),
            // §14: detected language, stored per message and exposed. Nullable —
            // no consumer may assume it is populated (see LanguageDetector).
            'language' => app(LanguageDetector::class)->detect($inbound->body),
            'external_message_id' => $inbound->externalMessageId,
            'quarantined' => $quarantined,
            // A-27/EC-05: when a resolver claimed authorship, SNAPSHOT the
            // reported type and the display-as-of-now, so historical
            // attribution keeps the old display even as the identity renames
            // forward.
            'channel_meta' => $author === null ? $inbound->channelMeta : ($inbound->channelMeta + [
                'reported_agent_type' => $author->reportedAgentType,
                'owner_phone_display' => $author->display,
            ]),
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
            // §14: outbound carries a detected language too — Doc 09's
            // translation features read it on both directions of the thread.
            'language' => app(LanguageDetector::class)->detect($body),
            'delivery_state' => 'queued',
            'client_idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * §13's staff-editable alt text for one stored image. The SLOT and this
     * writer are Doc 04's obligation; the editing surface (and the KB reuse it
     * feeds) belong to Doc 07 and Doc 10. Writes only the alt slot — it can
     * never touch scan_status or storage_key, so no accessibility edit can
     * change what a scan decided (SEC-04's absence stays an absence).
     */
    public function setMediaAltText(Message $message, int $index, ?string $altText): void
    {
        $this->updateMediaItem($message, $index, ['alt_text' => $altText]);
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

    /**
     * The delivery-state write half of FR-13's machine — legality lives in
     * DeliveryStateMachine; this is the only door to the column (T-01/R-05).
     * `$deliveryError === null` leaves the existing delivery_error untouched.
     *
     * @param  array<string, mixed>|null  $deliveryError
     */
    public function updateDeliveryState(Message $message, string $state, ?array $deliveryError = null): void
    {
        $update = ['delivery_state' => $state];

        if ($deliveryError !== null) {
            $update['delivery_error'] = json_encode($deliveryError, JSON_THROW_ON_ERROR);
        }

        Message::query()->whereKey($message->identifier())->update($update);
        $message->refresh();
    }

    /**
     * Patch ONE media item in the jsonb array (FR-10 step 5's scan_status
     * lifecycle: pending → scanning|clean|infected|failed|quarantined_pending|
     * rejected — the FR-06 trio plus EC-02's rejected and EC-11's held states).
     *
     * @param  array<string, mixed>  $patch
     */
    public function updateMediaItem(Message $message, int $index, array $patch): void
    {
        $media = $message->media;

        if (! array_key_exists($index, $media)) {
            throw new \DomainException(sprintf('message %s has no media item %d.', $message->identifier(), $index));
        }

        $media[$index] = array_merge($media[$index], $patch);

        Message::query()->whereKey($message->identifier())
            ->update(['media' => json_encode($media, JSON_THROW_ON_ERROR)]);
        $message->refresh();
    }

    /**
     * A SYSTEM thread marker (EC-02's media_rejected note, EC-11's staff
     * notice): kind=system, no delivery lifecycle, structurally undeliverable —
     * DeliverableKind has no System case (SEC-07), so no adapter can ever
     * serialize one. The renderer (Doc 07) maps channel_meta.system_code to its
     * i18n string; the row itself carries no user-visible text (N-03).
     *
     * @param  array<string, mixed>  $meta
     */
    public function persistSystemMarker(Conversation $conversation, string $systemCode, array $meta = []): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->identifier(),
            'direction' => 'in',
            'author_type' => 'system',
            'kind' => 'system',
            'body' => null,
            'channel_meta' => ['system_code' => $systemCode] + $meta,
        ]);
    }

    /** The channel's own id for a sent message, recorded once the adapter reports it. */
    public function recordExternalMessageId(Message $message, string $externalMessageId): void
    {
        Message::query()->whereKey($message->identifier())->update(['external_message_id' => $externalMessageId]);
        $message->refresh();
    }

    /**
     * A prior row carrying this channel-native id (FR-21: an inbound whose
     * external id already exists IS an edit — the frozen FR-08 contract needs
     * no edit flag). The partial index (tenant, external_message_id) serves it.
     */
    public function findByExternalMessageId(string $externalMessageId): ?Message
    {
        return Message::query()->where('external_message_id', $externalMessageId)->first();
    }

    /** One row by id, tenant-scoped (the lane worker's lookup). */
    public function findById(string $messageId): ?Message
    {
        return Message::query()->whereKey($messageId)->first();
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
