<?php

declare(strict_types=1);

namespace Tests\Support\Adapters;

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\AdapterHealth;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliverableKind;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\InboundMedia;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\SendResult;
use App\Domain\Conversations\Adapters\SupportsDelete;
use App\Domain\Conversations\Adapters\SupportsEdit;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Domain\Conversations\Messages\MessageKind;
use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * The FAKE REFERENCE ADAPTER (Doc 04 §21, CLAUDE.md §7 S4) — a code-level test
 * fixture, NOT the S0 mock-telegram HTTP service. The conformance suite never depends
 * on a mock service being up; it drives this class directly.
 *
 * It is deliberately the best-behaved adapter possible: deterministic normalization,
 * honest capability refusals, scripted failures. Doc 04's pipelines are proven against
 * it so that when Doc 05's Telegram adapter arrives, any deviation is Telegram's mess
 * to absorb in the adapter — never the core's.
 *
 * Raw webhook fixture shape (all fixtures synthetic per CLAUDE.md §8):
 * {update_id, chat_id, message_id, from: {id, display, username?, phone?}, kind,
 *  text?, media?: [{kind, url, mime, bytes?, width?, height?, duration?}],
 *  reply_to?, occurred_at (ISO-8601)}
 */
class FakeChannelAdapter implements ChannelAdapter, SupportsDelete, SupportsEdit, SupportsTyping
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    /** @var list<string> */
    public array $typingSignals = [];

    /** @var list<array{chat: string, message: string, payload: DeliverablePayload}> */
    public array $edits = [];

    /** @var list<array{chat: string, message: string}> */
    public array $deletes = [];

    /** @var list<SendResult> */
    private array $scriptedResults = [];

    private AdapterHealth $health;

    private int $externalIdSequence = 0;

    public function __construct(private readonly Capabilities $capabilities)
    {
        $this->health = AdapterHealth::ok();
    }

    /** Queue the result the next send() returns; FIFO across calls. */
    public function scriptResult(SendResult $result): void
    {
        $this->scriptedResults[] = $result;
    }

    public function setHealth(AdapterHealth $health): void
    {
        $this->health = $health;
    }

    public function handleWebhook(array $raw): NormalizedInbound
    {
        foreach (['update_id', 'chat_id', 'message_id', 'from', 'kind', 'occurred_at'] as $required) {
            if (! isset($raw[$required])) {
                throw new InvalidArgumentException("fake webhook payload is missing '{$required}'.");
            }
        }

        $from = $raw['from'];

        return new NormalizedInbound(
            externalChatId: (string) $raw['chat_id'],
            externalMessageId: (string) $raw['message_id'],
            sender: new InboundSender(
                externalUserId: (string) $from['id'],
                display: (string) $from['display'],
                username: isset($from['username']) ? (string) $from['username'] : null,
                phone: isset($from['phone']) ? (string) $from['phone'] : null,
            ),
            kind: MessageKind::from((string) $raw['kind']),
            body: isset($raw['text']) ? (string) $raw['text'] : null,
            media: array_map(
                static fn (array $item): InboundMedia => new InboundMedia(
                    kind: MessageKind::from((string) $item['kind']),
                    sourceUrl: (string) $item['url'],
                    mime: (string) $item['mime'],
                    bytes: isset($item['bytes']) ? (int) $item['bytes'] : null,
                    width: isset($item['width']) ? (int) $item['width'] : null,
                    height: isset($item['height']) ? (int) $item['height'] : null,
                    durationSeconds: isset($item['duration']) ? (int) $item['duration'] : null,
                ),
                $raw['media'] ?? [],
            ),
            replyToExternalMessageId: isset($raw['reply_to']) ? (string) $raw['reply_to'] : null,
            channelMeta: ['fake' => true],
            occurredAt: CarbonImmutable::parse((string) $raw['occurred_at'])->utc(),
            dedupeKey: 'fake:'.((string) $raw['update_id']),
        );
    }

    public function send(OutboundMessage $message): SendResult
    {
        $refusal = $this->capabilityRefusal($message->payload);
        if ($refusal instanceof SendResult) {
            return $refusal;
        }

        $this->sent[] = $message;

        if ($this->scriptedResults !== []) {
            return array_shift($this->scriptedResults);
        }

        return SendResult::sent('fake-out-'.(++$this->externalIdSequence));
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function health(): AdapterHealth
    {
        return $this->health;
    }

    public function sendTyping(string $externalChatId): void
    {
        if (! $this->capabilities->supportsTyping) {
            throw new DomainException('this channel does not support typing signals (capability off).');
        }

        $this->typingSignals[] = $externalChatId;
    }

    public function editMessage(string $externalChatId, string $externalMessageId, DeliverablePayload $payload): SendResult
    {
        if (! $this->capabilities->supportsEdit) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        $this->edits[] = ['chat' => $externalChatId, 'message' => $externalMessageId, 'payload' => $payload];

        return SendResult::sent($externalMessageId);
    }

    public function deleteMessage(string $externalChatId, string $externalMessageId): SendResult
    {
        if (! $this->capabilities->supportsDelete) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        $this->deletes[] = ['chat' => $externalChatId, 'message' => $externalMessageId];

        return SendResult::sent($externalMessageId);
    }

    /**
     * The refusals a real channel's API would answer with, classified per FR-14 —
     * text over max_text and media kinds the channel cannot carry are invalid content,
     * never a retry loop.
     */
    private function capabilityRefusal(DeliverablePayload $payload): ?SendResult
    {
        if ($payload->body !== null && mb_strlen($payload->body) > $this->capabilities->maxText) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        if ($payload->kind !== DeliverableKind::Text && ! $this->capabilities->allowsMediaKind($payload->kind)) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        return null;
    }
}
