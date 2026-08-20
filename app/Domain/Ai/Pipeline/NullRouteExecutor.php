<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/** Step 6's default: no route executes before its owning step exists. */
final class NullRouteExecutor implements RouteExecutor
{
    public function execute(string $route, string $decisionId, string $conversationId, string $messageId, string $language, array $classification): void
    {
        // Deliberately nothing: rg_override attaches at step 7,
        // chatbot_turn/handoff_path at step 9.
    }
}
