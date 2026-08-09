<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

/**
 * An INBOUND message whose author is NOT the customer (ruling A-27, the
 * fourth ruled core edit). The one real case today is Doc 05's owner-phone
 * reply (T-03/BR-03): a business account's owner replies from the phone, and
 * Telegram delivers it as an inbound business_message — but it is a STAFF
 * reply and must attribute as one, never hidden.
 *
 * `authorType` is a messages.author_type CHECK value ('agent' here);
 * `authorId` is the reserved staff identity's id. `reportedAgentType` is the
 * REPORTING distinction (T-03: "owner_phone") — a message author_type stays
 * the four-value enum, the reporting flavour rides alongside.
 */
final class InboundAuthor
{
    public function __construct(
        public readonly string $authorType,
        public readonly string $authorId,
        public readonly string $reportedAgentType,
        public readonly ?string $display = null,
    ) {}
}
