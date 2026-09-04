<?php

declare(strict_types=1);

namespace App\Domain\Webchat;

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\AdapterHealth;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliverableKind;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\InboundMedia;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\SendResult;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Domain\Conversations\Messages\MessageKind;
use App\Domain\Webchat\Realtime\WebchatPusher;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * THE WEBCHAT CHANNEL ADAPTER (Doc 13 T-02) — Doc 04 FR-08 implemented for the
 * website widget, through the FROZEN contract and nothing else (Doc 13 AC-01:
 * the unmodified conformance suite; zero core edits, the Doc 05 standard).
 *
 * Capabilities are INJECTED (the Telegram precedent — one adapter, one class,
 * never two code paths); the provider declares T-02's envelope verbatim:
 * max_text 4000 · media photo/video/document (+ voice only while the platform
 * flag `widget.voice_recording` is on) · typing yes · edit no · delete no ·
 * buttons yes · reply_window none. A capability that lies here is the first
 * sabotage the plan names — the suite catches it.
 *
 * INBOUND: the widget's `POST /api/widget/v1/messages` envelope, one message
 * per call, normalized purely per item. `external_chat_id` IS the visitor id
 * (Doc 02's durable visitor_id — A-61: the channel's own fact, the anchor of
 * record); `channel_ref_id` is the widget id. The dedupe key is stable per
 * (widget, visitor, client message id) — the widget's idempotency key becomes
 * the channel's message id, so a retried POST is the same message (T-03).
 * A `note` kind is refused at construction (BR-01/N-04).
 *
 * OUTBOUND: the ONLY door toward a visitor — a frame on the visitor's own
 * private `thread` channel (WebchatPusher; Doc 02's channel-auth boundary).
 * The frame carries text bodies and media DESCRIPTORS (storage keys, never
 * bytes or signed URLs — the cursor endpoint mints URLs, FR-21's one sync
 * model). `sent` means the frame left the platform; `delivered` is NOT this
 * adapter's word — it is earned by the visitor client's receipt
 * (`POST receipts`, T-02 "delivered on socket ack") through
 * DeliveryStateMachine, and never faked anywhere else (AC-01).
 *
 * The push is a Closure (the Telegram tokenResolver shape) so the conformance
 * subclass drives failure classes against a scripted transport, never a mock
 * service.
 */
final class WebchatAdapter implements ChannelAdapter, SupportsTyping
{
    public const CHANNEL = 'webchat';

    public const EVENT_MESSAGE = 'webchat.message';

    public const EVENT_TYPING = 'webchat.typing';

    /** @var array<string, MessageKind> */
    private const KINDS = [
        'text' => MessageKind::Text,
        'photo' => MessageKind::Photo,
        'video' => MessageKind::Video,
        'voice' => MessageKind::Voice,
        'audio' => MessageKind::Audio,
        'document' => MessageKind::Document,
        'sticker' => MessageKind::Sticker,
        'system' => MessageKind::System,
        'note' => MessageKind::Note, // present so the contract's own constructor refuses it (BR-01)
    ];

    /**
     * @param  Closure(string, string, string, array<string, mixed>): void  $push  (visitorId, suffix, event, payload)
     * @param  Closure(): AdapterHealth  $health
     */
    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly Closure $push,
        private readonly Closure $health,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public function handleWebhook(array $raw): NormalizedInbound
    {
        foreach (['visitor_id', 'widget_id', 'message_id', 'kind', 'occurred_at'] as $required) {
            if (! isset($raw[$required]) || $raw[$required] === '') {
                throw new InvalidArgumentException("webchat envelope is missing '{$required}'.");
            }
        }

        $kindName = self::str($raw['kind'], 'kind');
        $kind = self::KINDS[$kindName] ?? throw new InvalidArgumentException("webchat envelope carries an unknown kind '{$kindName}'.");

        $visitorId = self::str($raw['visitor_id'], 'visitor_id');
        $widgetId = self::str($raw['widget_id'], 'widget_id');
        $messageId = self::str($raw['message_id'], 'message_id');
        $display = isset($raw['display']) && is_string($raw['display']) && trim($raw['display']) !== ''
            ? trim($raw['display'])
            : $visitorId; // an id, never a literal label — the render localizes "visitor" (N-03)

        $media = [];
        if (isset($raw['media']) && is_array($raw['media'])) {
            foreach ($raw['media'] as $item) {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('webchat media items are objects.');
                }
                $media[] = new InboundMedia(
                    kind: $kind,
                    sourceUrl: self::str($item['source_url'] ?? '', 'media.source_url'),
                    mime: self::str($item['mime'] ?? 'application/octet-stream', 'media.mime'),
                    bytes: self::intOrNull($item['bytes'] ?? null),
                    width: self::intOrNull($item['width'] ?? null),
                    height: self::intOrNull($item['height'] ?? null),
                    durationSeconds: self::intOrNull($item['duration_seconds'] ?? null),
                );
            }
        }

        return new NormalizedInbound(
            externalChatId: $visitorId,
            externalMessageId: $messageId,
            sender: new InboundSender(
                externalUserId: $visitorId,
                display: $display,
                username: null,
                phone: null, // pre-chat phones are staff-collected FIELDS, never sender identity (Doc 08 BR-02)
            ),
            kind: $kind,
            body: isset($raw['body']) && is_string($raw['body']) ? $raw['body'] : null,
            media: $media,
            replyToExternalMessageId: isset($raw['reply_to']) && is_string($raw['reply_to']) ? $raw['reply_to'] : null,
            channelMeta: ['widget_id' => $widgetId, 'page_url' => isset($raw['page_url']) && is_string($raw['page_url']) ? $raw['page_url'] : null],
            occurredAt: CarbonImmutable::parse(self::str($raw['occurred_at'], 'occurred_at'))->utc(),
            dedupeKey: sprintf('webchat:%s:%s:%s', $widgetId, $visitorId, $messageId),
        );
    }

    public function send(OutboundMessage $message): SendResult
    {
        $refusal = $this->capabilityRefusal($message);
        if ($refusal !== null) {
            return $refusal;
        }

        $externalMessageId = 'wm_'.Str::uuid7()->toString();

        $payload = [
            'message_ref' => $externalMessageId,
            'kind' => $message->payload->kind->value,
            'body' => $message->payload->body,
            'reply_to' => $message->replyToExternalMessageId,
            'media' => array_map(static fn ($item): array => [
                'storage_key' => $item->storageKey,
                'mime' => $item->mime,
                'bytes' => $item->bytes,
                'width' => $item->width,
                'height' => $item->height,
                'duration_seconds' => $item->durationSeconds,
            ], $message->payload->media),
            'buttons' => $message->payload->buttons,
        ];

        try {
            ($this->push)($message->externalChatId, WebchatPusher::SUFFIX_THREAD, self::EVENT_MESSAGE, $payload);
        } catch (Throwable $e) {
            return SendResult::failed($this->classify($e));
        }

        return SendResult::sent($externalMessageId);
    }

    public function sendTyping(string $externalChatId): void
    {
        if (! $this->capabilities->supportsTyping) {
            throw new DomainException('typing is not a declared capability of this webchat profile (FR-18).');
        }

        try {
            ($this->push)($externalChatId, WebchatPusher::SUFFIX_THREAD, self::EVENT_TYPING, ['author' => 'staff']);
        } catch (Throwable) {
            // typing is a courtesy, never a dependency (Doc 04 FR-18)
        }
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function health(): AdapterHealth
    {
        try {
            return ($this->health)();
        } catch (Throwable $e) {
            return AdapterHealth::down($e->getMessage());
        }
    }

    /** The envelope is JSON from the widget door: scalars only, never objects cast blind. */
    private static function str(mixed $value, string $field): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException("webchat envelope field '{$field}' must be a string.");
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException('webchat media dimensions must be integers.');
    }

    private function capabilityRefusal(OutboundMessage $message): ?SendResult
    {
        $body = $message->payload->body;
        if ($body !== null && mb_strlen($body) > $this->capabilities->maxText) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        if ($message->payload->kind !== DeliverableKind::Text && ! $this->capabilities->allowsMediaKind($message->payload->kind)) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        return null;
    }

    /**
     * The frozen 8-code table (Doc 04 FR-13), from the socket layer's point of
     * view: Reverb refusing (its HTTP events API answering non-2xx) is the
     * channel being unavailable (retryable); anything the transport threw
     * below that is a network error (retryable). No reply window, no user
     * blocking at this layer (Doc 04's conversation/customer guards refuse
     * BEFORE the adapter is reached), no credentials the adapter holds.
     */
    private function classify(Throwable $e): AdapterError
    {
        if ($e instanceof BroadcastException) {
            return new AdapterError(DeliveryFailureReason::ChannelUnavailable);
        }

        if ($e instanceof WebchatTransportError) {
            return new AdapterError($e->reason, $e->retryAfterSeconds);
        }

        return new AdapterError(DeliveryFailureReason::NetworkError);
    }
}
