<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/** The step-6 default: no chatbot anywhere until step 8's switches exist. */
final class DisabledEverywhere implements ChatbotEnablement
{
    public function enabledFor(string $surface, string $channel, ?string $endpointRef): bool
    {
        return false;
    }
}
