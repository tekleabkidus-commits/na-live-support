<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * What ChannelAdapter::send returns (Doc 04 FR-08): sent (with the channel-native
 * message id where the channel provides one) or failed (with a classified error).
 *
 * `sent` here means the CHANNEL ACCEPTED the message. It never rolls back (EC-05):
 * late channel invalidation becomes a post_send_failed system annotation, not a state
 * transition. `delivered` is a separate, receipt-driven state the adapter reports only
 * where the channel supports receipts (FR-13; Doc 05 defines the Telegram mapping).
 */
final class SendResult
{
    private function __construct(
        public readonly SendState $state,
        public readonly ?string $externalMessageId,
        public readonly ?AdapterError $error,
    ) {}

    public static function sent(?string $externalMessageId): self
    {
        return new self(SendState::Sent, $externalMessageId, null);
    }

    public static function failed(AdapterError $error): self
    {
        return new self(SendState::Failed, null, $error);
    }
}
