<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\AdapterHealth;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliverableKind;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\SendResult;
use App\Domain\Conversations\Adapters\SupportsDelete;
use App\Domain\Conversations\Adapters\SupportsEdit;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Domain\Conversations\Messages\MessageKind;
use App\Domain\Telegram\Api\TelegramApi;
use App\Domain\Telegram\Api\TelegramApiError;
use App\Domain\Telegram\Api\TelegramTransportError;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * THE FIRST REAL ChannelAdapter (Doc 05 T-01; Doc 04 FR-08's frozen contract;
 * R-16 made real). Everything Telegram-peculiar lives HERE; the core is
 * consumed through its existing doors and touched by ZERO files in this
 * module (SS-04's diff assertion; A-20/A-22's ruled edits are the enumerated
 * exceptions and live outside this directory).
 *
 * - Capabilities are INJECTED per bot mode (T-01's declared set — business
 *   carries ReplyWindow::duration, direct carries none; one adapter, one
 *   class, never two code paths, FR-05).
 * - The bot token resolves per call through a closure onto the registry
 *   (T-02: no per-bot deploys); resolution failure maps to FS-03's
 *   credentials_unavailable, retryable, with no fallback storage by design.
 * - handleWebhook normalizes MESSAGE-BEARING updates only. Non-message
 *   updates (business_connection, my_chat_member, callback_query) cannot
 *   produce a NormalizedInbound and route adapter-side BEFORE this door
 *   (S23 plan, inherited row 3) — receiving one here throws.
 * - STEP-3 SKELETON BOUNDARY, stated: text normalization only; media-bearing
 *   updates throw until the FR-13 mapping lands with the media chain
 *   (step 5) — the webhook route does not exist before step 4, so no real
 *   traffic can reach the gap.
 * - Telegram's `date` is METADATA (EC-13/ADR 0010): preserved exact in
 *   occurredAt/channel_meta, never an ordering input.
 */
final class TelegramAdapter implements ChannelAdapter, SupportsDelete, SupportsEdit, SupportsTyping
{
    /**
     * @param  Closure(array<string, mixed>): string  $tokenResolver
     * @param  Closure(): (Outbound\TelegramRateLimiter|null)|null  $rateLimiterResolver
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly Capabilities $capabilities,
        private readonly string $botKey,
        private readonly Closure $tokenResolver,
        // A LAZY resolver, not the limiter itself: eager instantiation at
        // provider-boot resolved the container graph on every request and
        // perturbed the volatile-Redis-outage tests (WebhookHmac/Quota).
        // Resolved only when a real send needs it.
        private readonly ?Closure $rateLimiterResolver = null,
    ) {}

    public function handleWebhook(array $raw): NormalizedInbound
    {
        // S23 step 5: production traffic arrives as the router's ENVELOPE
        // {bot, update, owner_phone?}; the conformance suite drives flat
        // updates (constructor botKey). Same door, one code path.
        $update = is_array($raw['update'] ?? null) ? $raw['update'] : $raw;
        $botKey = is_string($raw['bot'] ?? null) && $raw['bot'] !== '' ? $raw['bot'] : $this->botKey;
        $ownerPhone = ($raw['owner_phone'] ?? null) === true;

        $message = $update['message'] ?? $update['business_message']
            ?? $update['edited_message'] ?? $update['edited_business_message'] ?? null;
        $updateId = $update['update_id'] ?? null;

        if (! is_array($message) || ! is_int($updateId)) {
            throw new InvalidArgumentException(
                'not a message-bearing Telegram update — lifecycle updates route adapter-side before this door (Doc 05 plan row 3).',
            );
        }

        [$kind, $body, $media] = $this->mapContent($message, $botKey);

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $date = is_int($message['date'] ?? null) ? $message['date'] : 0;

        $chatId = $chat['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $fromId = $from['id'] ?? null;

        $replyTo = null;
        $replyMessage = $message['reply_to_message'] ?? null;
        if (is_array($replyMessage) && is_scalar($replyMessage['message_id'] ?? null)) {
            $replyTo = (string) $replyMessage['message_id'];
        }

        $display = trim(implode(' ', array_filter([
            is_string($from['first_name'] ?? null) ? $from['first_name'] : null,
            is_string($from['last_name'] ?? null) ? $from['last_name'] : null,
        ])));

        return new NormalizedInbound(
            externalChatId: is_scalar($chatId) ? (string) $chatId : '',
            externalMessageId: is_scalar($messageId) ? (string) $messageId : '',
            sender: new InboundSender(
                externalUserId: is_scalar($fromId) ? (string) $fromId : '',
                display: $display !== '' ? $display : ('telegram:'.(is_scalar($fromId) ? (string) $fromId : 'unknown')),
                username: is_string($from['username'] ?? null) ? $from['username'] : null,
            ),
            kind: $kind,
            body: $body,
            media: $media,
            replyToExternalMessageId: $replyTo,
            channelMeta: [
                'telegram_date' => $date,
                'message_id' => is_scalar($messageId) ? $messageId : null,
                'entities' => is_array($message['entities'] ?? null) ? $message['entities'] : [],
                'connection_id' => is_string($message['business_connection_id'] ?? null)
                    ? $message['business_connection_id']
                    : null,
                // T-03/FR-11: router-detected owner-phone marker — the
                // attribution CONTRACT gap is escalated at the step-5
                // checkpoint; the detection is proven here regardless.
                'owner_phone' => $ownerPhone,
            ],
            occurredAt: CarbonImmutable::createFromTimestamp($date, 'UTC'),
            dedupeKey: sprintf('%s:%d', $botKey, $updateId), // FR-11
        );
    }

    public function send(OutboundMessage $message): SendResult
    {
        $context = $message->channelMeta;
        if ($message->payload->body !== null && mb_strlen($message->payload->body) > $this->capabilities->maxText) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        if ($message->payload->kind !== DeliverableKind::Text && ! $this->capabilities->allowsMediaKind($message->payload->kind)) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        try {
            $token = ($this->tokenResolver)($context);
        } catch (Throwable) {
            // FS-03: credentials unavailable is retryable and has NO fallback
            // storage path by design.
            return SendResult::failed(new AdapterError(DeliveryFailureReason::CredentialsUnavailable));
        }

        // FR-20/T-08: the send buckets gate here. A refusal is retryable
        // rate_limited — the lane waits in queued, never drops (FR-20).
        $botId = is_string($context['bot_id'] ?? null) ? $context['bot_id'] : $this->botKey;
        $rateLimiter = $this->rateLimiterResolver !== null ? ($this->rateLimiterResolver)() : null;
        if ($rateLimiter instanceof Outbound\TelegramRateLimiter
            && ! $rateLimiter->allow($botId, $message->externalChatId, (float) (hrtime(true) / 1_000_000_000))) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::RateLimited));
        }

        try {
            $result = $this->api->call($token, ...$this->sendCall($message));

            $externalId = $result['message_id'] ?? null;

            return SendResult::sent(is_scalar($externalId) ? (string) $externalId : null);
        } catch (TelegramApiError|TelegramTransportError $e) {
            $classified = ErrorClassifier::classify($e);
            // R-05: a 429 feeds the adaptive slowdown penalty.
            if ($rateLimiter instanceof Outbound\TelegramRateLimiter && $classified->reason === DeliveryFailureReason::RateLimited) {
                $rateLimiter->penalize($botId);
            }

            return SendResult::failed($classified);
        }
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function health(): AdapterHealth
    {
        // The real webhook/rollup health view is FR-29's (step 11); the
        // contract answer is always shaped.
        return AdapterHealth::ok();
    }

    public function sendTyping(string $externalChatId): void
    {
        if (! $this->capabilities->supportsTyping) {
            throw new DomainException('typing is not in this bot\'s declared capability set.');
        }

        try {
            $token = ($this->tokenResolver)([]);
            $this->api->call($token, 'sendChatAction', ['chat_id' => $externalChatId, 'action' => 'typing']);
        } catch (Throwable) {
            // FR-19: typing is best-effort; failures (incl. no resolvable bot
            // context) are silently ignored.
        }
    }

    public function editMessage(string $externalChatId, string $externalMessageId, \App\Domain\Conversations\Adapters\DeliverablePayload $payload): SendResult
    {
        if (! $this->capabilities->supportsEdit) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        try {
            $token = ($this->tokenResolver)([]);
        } catch (Throwable) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::CredentialsUnavailable));
        }

        try {
            $this->api->call($token, 'editMessageText', [
                'chat_id' => $externalChatId,
                'message_id' => (int) $externalMessageId,
                'text' => (string) $payload->body,
            ]);

            return SendResult::sent($externalMessageId);
        } catch (TelegramApiError|TelegramTransportError $e) {
            return SendResult::failed(ErrorClassifier::classify($e));
        }
    }

    public function deleteMessage(string $externalChatId, string $externalMessageId): SendResult
    {
        if (! $this->capabilities->supportsDelete) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        try {
            $token = ($this->tokenResolver)([]);
        } catch (Throwable) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::CredentialsUnavailable));
        }

        try {
            $this->api->call($token, 'deleteMessage', [
                'chat_id' => $externalChatId,
                'message_id' => (int) $externalMessageId,
            ]);

            return SendResult::sent($externalMessageId);
        } catch (TelegramApiError|TelegramTransportError $e) {
            return SendResult::failed(ErrorClassifier::classify($e));
        }
    }

    /**
     * FR-13's inbound content mapping. Media items carry the OPAQUE A-26
     * reference `telegram-file:{bot}/{file_id}` — the tokened URL never
     * exists here (T-07); the chain resolves per fetch attempt.
     *
     * @param  array<array-key, mixed>  $message
     * @return array{0: MessageKind, 1: ?string, 2: list<\App\Domain\Conversations\Adapters\InboundMedia>}
     */
    private function mapContent(array $message, string $botKey): array
    {
        if (isset($message['text']) && is_string($message['text'])) {
            return [MessageKind::Text, $message['text'], []];
        }

        $caption = is_string($message['caption'] ?? null) ? $message['caption'] : null;
        $ref = fn (mixed $fileId): string => sprintf('telegram-file:%s/%s', $botKey, is_string($fileId) ? $fileId : '');
        $int = fn (mixed $v): ?int => is_int($v) ? $v : null;

        if (is_array($message['photo'] ?? null) && $message['photo'] !== []) {
            // FR-13: the LARGEST size (Telegram orders small→large).
            $sizes = array_values(array_filter($message['photo'], 'is_array'));
            $largest = $sizes[count($sizes) - 1];

            return [MessageKind::Photo, $caption, [new \App\Domain\Conversations\Adapters\InboundMedia(
                MessageKind::Photo, $ref($largest['file_id'] ?? ''), 'image/jpeg',
                $int($largest['file_size'] ?? null), $int($largest['width'] ?? null), $int($largest['height'] ?? null),
            )]];
        }

        foreach ([
            ['voice', MessageKind::Voice, 'audio/ogg'],
            ['video', MessageKind::Video, 'video/mp4'],
            ['video_note', MessageKind::Video, 'video/mp4'],
            ['audio', MessageKind::Audio, 'audio/mpeg'],
            ['document', MessageKind::Document, 'application/octet-stream'],
        ] as [$field, $mediaKind, $fallbackMime]) {
            $part = $message[$field] ?? null;
            if (! is_array($part)) {
                continue;
            }

            $mime = is_string($part['mime_type'] ?? null) ? $part['mime_type'] : $fallbackMime;

            return [$mediaKind, $caption, [new \App\Domain\Conversations\Adapters\InboundMedia(
                $mediaKind, $ref($part['file_id'] ?? ''), $mime,
                $int($part['file_size'] ?? null), $int($part['width'] ?? null), $int($part['height'] ?? null),
                $int($part['duration'] ?? null),
            )]];
        }

        if (is_array($message['sticker'] ?? null)) {
            $sticker = $message['sticker'];
            $animated = ($sticker['is_animated'] ?? false) === true || ($sticker['is_video'] ?? false) === true;

            // EC-10/FR-13: animated stickers store as-is; the static-thumb-v1
            // rendering (and its placeholder fallback) is the thumbnail
            // chain's job — a thumbnail failure never blocks the message.
            return [MessageKind::Sticker, $caption, [new \App\Domain\Conversations\Adapters\InboundMedia(
                MessageKind::Sticker, $ref($sticker['file_id'] ?? ''),
                $animated ? 'application/x-tgsticker' : 'image/webp',
                $int($sticker['file_size'] ?? null), $int($sticker['width'] ?? null), $int($sticker['height'] ?? null),
            )]];
        }

        // Telegram cannot express an internal note (BR-01/N-04); a message
        // with no mappable customer content refuses here.
        throw new InvalidArgumentException('unmappable message content: no customer-content field this adapter maps.');
    }

    /** @return array{0: string, 1: array<string, mixed>} [method, params] by payload kind (FR-16's mapping grows at step 7). */
    private function sendCall(OutboundMessage $message): array
    {
        $params = ['chat_id' => $message->externalChatId];

        if (is_string($message->channelMeta['business_connection_id'] ?? null)) {
            $params['business_connection_id'] = $message->channelMeta['business_connection_id'];
        }

        if ($message->payload->kind === DeliverableKind::Text) {
            return ['sendMessage', $params + ['text' => (string) $message->payload->body]];
        }

        $method = match ($message->payload->kind) {
            DeliverableKind::Photo => 'sendPhoto',
            DeliverableKind::Voice => 'sendVoice',
            DeliverableKind::Audio => 'sendAudio',
            DeliverableKind::Video => 'sendVideo',
            DeliverableKind::Document => 'sendDocument',
            DeliverableKind::Sticker => 'sendSticker',
        };

        // Media upload mechanics (multipart/signed fetch) land with step 7's
        // outbound work; the method mapping is T-01's declared shape.
        return [$method, $params + ['caption' => $message->payload->body]];
    }
}
