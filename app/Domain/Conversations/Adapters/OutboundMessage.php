<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * What the core hands ChannelAdapter::send (Doc 04 FR-08 outbound).
 *
 * The payload property is typed {@see DeliverablePayload} and non-nullable — that type
 * choice, not reviewer vigilance, is what keeps notes out of every adapter (SEC-07).
 * The idempotency key is Doc 04 T-03's outbound half (Doc 01 T-08 convention), stored
 * on the message row and passed through so adapters that support native idempotency can
 * use it.
 */
final class OutboundMessage
{
    /**
     * @param  array<string, mixed>  $channelMeta
     */
    public function __construct(
        public readonly string $externalChatId,
        public readonly DeliverablePayload $payload,
        public readonly ?string $replyToExternalMessageId = null,
        public readonly array $channelMeta = [],
        public readonly ?string $idempotencyKey = null,
    ) {}
}
