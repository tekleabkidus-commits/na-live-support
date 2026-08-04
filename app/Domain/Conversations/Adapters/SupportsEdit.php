<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * Capability extension: declared by `supports_edit` (Doc 04 FR-08/FR-22).
 *
 * Staff-side outbound edit, only where the channel allows it and within channel limits
 * (FR-22). The payload is a DeliverablePayload for the same SEC-07 reason send()'s is.
 */
interface SupportsEdit
{
    public function editMessage(string $externalChatId, string $externalMessageId, DeliverablePayload $payload): SendResult;
}
