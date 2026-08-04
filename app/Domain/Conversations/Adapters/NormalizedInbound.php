<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

use App\Domain\Conversations\Messages\MessageKind;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * What handleWebhook returns (Doc 04 FR-08 inbound) — one channel message, normalized.
 *
 * `occurredAt` is the CHANNEL's timestamp and is METADATA ONLY (BR-04, FR-07): display
 * order is platform receipt order, and EC-10's "sent earlier" line is where this value
 * resurfaces. `dedupeKey` feeds the Redis SETNX 48 h window (FR-10 step 2, T-03).
 *
 * A channel can never originate staff-internal content: constructing this with
 * kind=note throws (BR-01's inbound edge; the outbound edge is DeliverableKind's
 * absent case).
 */
final class NormalizedInbound
{
    /**
     * @param  list<InboundMedia>  $media
     * @param  array<string, mixed>  $channelMeta
     */
    public function __construct(
        public readonly string $externalChatId,
        public readonly string $externalMessageId,
        public readonly InboundSender $sender,
        public readonly MessageKind $kind,
        public readonly ?string $body,
        public readonly array $media,
        public readonly ?string $replyToExternalMessageId,
        public readonly array $channelMeta,
        public readonly CarbonImmutable $occurredAt,
        public readonly string $dedupeKey,
    ) {
        if ($this->kind === MessageKind::Note) {
            throw new DomainException(
                'a channel cannot originate an internal note (BR-01); adapters normalize customer and system kinds only.',
            );
        }
    }
}
