<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Rg\RgOverride;

/**
 * The RouteExecutor binding: rg_override -> RgOverride (step 7). The
 * chatbot_turn and handoff_path arms attach at step 9; copilot_context is a
 * no-act route (the decision row is the delivery). RG FIRST is FR-07's
 * decide order, and this dispatcher never re-evaluates it (sabotage #5's
 * subject lives in TurnDecision, not here).
 */
final class RouteDispatcher implements RouteExecutor
{
    public function __construct(private readonly RgOverride $rg) {}

    public function execute(string $route, string $decisionId, string $conversationId, string $messageId, string $language, array $classification): void
    {
        if ($route === 'rg_override') {
            $this->rg->execute($decisionId, $conversationId, $messageId, $language, $classification);
        }
        // chatbot_turn / handoff_path: step 9. copilot_context: no act.
    }
}
