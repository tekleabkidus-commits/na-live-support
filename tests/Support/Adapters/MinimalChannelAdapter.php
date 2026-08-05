<?php

declare(strict_types=1);

namespace Tests\Support\Adapters;

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
use App\Domain\Conversations\Messages\MessageKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * THE SECOND FAKE ADAPTER (Doc 04 SS-02 per ruling R-16): a deliberately
 * minimal, SMS-like channel — 320-character texts, NO media, NO typing, NO
 * edit/delete, NO buttons, a 24-hour reply window — implementing ONLY the
 * base ChannelAdapter contract (none of the Supports* extensions), with a
 * raw payload shaped nothing like the reference adapter's.
 *
 * Its entire purpose is the architecture proof: driven through the SAME
 * conformance suite and the SAME product pipelines with ZERO core edits —
 * the commit introducing this file touches tests/ only, and that diff is the
 * proof R-16 requires. Nothing WhatsApp-shaped is built (R-16's own words);
 * the literal WhatsApp demonstration re-proves if such an adapter is ever
 * built (§19).
 */
class MinimalChannelAdapter implements ChannelAdapter
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    /** @var list<SendResult> */
    private array $scriptedResults = [];

    private AdapterHealth $health;

    private int $externalIdSequence = 0;

    public function __construct(private readonly Capabilities $capabilities)
    {
        $this->health = AdapterHealth::ok();
    }

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
        foreach (['id', 'chat_ref', 'message_id', 'who', 'kind', 'occurred_at'] as $required) {
            if (! isset($raw[$required])) {
                throw new InvalidArgumentException("minimal webhook payload is missing '{$required}'.");
            }
        }

        if ($raw['kind'] !== 'text') {
            throw new InvalidArgumentException('the minimal channel carries text only.');
        }

        $who = $raw['who'];

        return new NormalizedInbound(
            externalChatId: (string) $raw['chat_ref'],
            externalMessageId: (string) $raw['message_id'],
            sender: new InboundSender(
                externalUserId: (string) $who['ref'],
                display: (string) $who['label'],
                username: null,
                phone: isset($who['msisdn']) ? (string) $who['msisdn'] : null,
            ),
            kind: MessageKind::Text,
            body: isset($raw['content']) ? (string) $raw['content'] : null,
            media: [],
            replyToExternalMessageId: null,
            channelMeta: ['minimal' => true],
            occurredAt: CarbonImmutable::parse((string) $raw['occurred_at'])->utc(),
            dedupeKey: 'min:'.((string) $raw['id']),
        );
    }

    public function send(OutboundMessage $message): SendResult
    {
        if ($message->payload->body !== null && mb_strlen($message->payload->body) > $this->capabilities->maxText) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        if ($message->payload->kind !== DeliverableKind::Text && ! $this->capabilities->allowsMediaKind($message->payload->kind)) {
            return SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent));
        }

        $this->sent[] = $message;

        if ($this->scriptedResults !== []) {
            return array_shift($this->scriptedResults);
        }

        return SendResult::sent('min-out-'.(++$this->externalIdSequence));
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function health(): AdapterHealth
    {
        return $this->health;
    }
}
