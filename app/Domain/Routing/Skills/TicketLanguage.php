<?php

declare(strict_types=1);

namespace App\Domain\Routing\Skills;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 T-05 — "language sourced from ticket/conversation language (Doc 04)": the latest
 * CUSTOMER message's detected language on the ticket's conversation (Doc 04's
 * LanguageDetector writes messages.language at persist). Null when nothing inbound exists.
 */
final class TicketLanguage
{
    public function forConversation(string $conversationId): ?string
    {
        $language = DB::table('messages')->where('conversation_id', $conversationId)
            ->where('author_type', 'customer')->whereNotNull('language')
            ->orderByDesc('created_at')->value('language');

        return is_string($language) && $language !== '' ? $language : null;
    }
}
