<?php

declare(strict_types=1);

namespace App\Domain\Ai\Honesty;

use Illuminate\Support\Facades\DB;

/**
 * FR-19a's "first greeting": the assistant's FIRST outbound message in a
 * conversation is prefixed with the (gated) greeting for the customer's
 * language. Every AI compose in the turn and the handoff template go
 * through here — EXCEPT the RG template (BR-03: byte-verified operator
 * text; the template carries its own wording and is never altered).
 */
final class FirstGreeting
{
    public function __construct(private readonly Disclosure $disclosure) {}

    public function prefixIfFirst(string $conversationId, string $language, string $text): string
    {
        $spoken = DB::table('messages')->where('conversation_id', $conversationId)->where('direction', 'out')->where('author_type', 'ai')->exists();
        if ($spoken) {
            return $text;
        }

        return $this->disclosure->greeting($language)."\n\n".$text;
    }
}
