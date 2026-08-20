<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/**
 * The enablement seam FR-07 consults (Doc 05/13 endpoint flags + FR-18 kill
 * switches). Step 8's implementation combines both; the container default is
 * DisabledEverywhere until then — the chatbot is structurally unreachable
 * before its kill switches exist (the plan's safety ordering as physics).
 */
interface ChatbotEnablement
{
    public function enabledFor(string $surface, string $channel, ?string $endpointRef): bool;
}
