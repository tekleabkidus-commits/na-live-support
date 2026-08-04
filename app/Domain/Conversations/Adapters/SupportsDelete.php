<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * Capability extension: declared by `supports_delete` (Doc 04 FR-08/FR-22).
 *
 * Gated by the `telegram.message.delete` permission key where the surface is Telegram
 * (the KEY is wired this session; Telegram behavior is Doc 05's — pre-agreed bucket).
 */
interface SupportsDelete
{
    public function deleteMessage(string $externalChatId, string $externalMessageId): SendResult;
}
