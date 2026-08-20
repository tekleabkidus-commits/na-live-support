<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Chatbot\ChatbotTurn;
use App\Domain\Ai\Chatbot\HandoffDecision;
use App\Domain\Ai\Rg\RgOverride;
use App\Models\Conversation;

/**
 * The RouteExecutor binding: rg_override -> RgOverride (step 7). The
 * chatbot_turn and handoff_path arms attach at step 9; copilot_context is a
 * no-act route (the decision row is the delivery). RG FIRST is FR-07's
 * decide order, and this dispatcher never re-evaluates it (sabotage #5's
 * subject lives in TurnDecision, not here).
 */
final class RouteDispatcher implements RouteExecutor
{
    public function __construct(
        private readonly RgOverride $rg,
        private readonly ChatbotTurn $turn,
        private readonly HandoffDecision $handoffs,
    ) {}

    public function execute(string $route, string $decisionId, string $conversationId, string $messageId, string $language, array $classification): void
    {
        if (str_starts_with($route, 'bypass_handoff:')) {
            // EC-12 / FS-01 at the classify stage: straight to a human with the
            // template, reason from the closed list (quota_exhausted | degraded).
            $this->handoffs->handoff(substr($route, strlen('bypass_handoff:')), $decisionId, Conversation::query()->findOrFail($conversationId), $messageId, $language, $classification);

            return;
        }

        match ($route) {
            'rg_override' => $this->rg->execute($decisionId, $conversationId, $messageId, $language, $classification),
            'chatbot_turn' => $this->turn->run($decisionId, $conversationId, $messageId, $language, $classification),
            // FR-07: chatbot not enabled here → the handoff path directly
            // (the packet + FR-28 template; the human queue is Doc 11's).
            'handoff_path' => $this->handoffs->handoff('low_confidence', $decisionId, Conversation::query()->findOrFail($conversationId), $messageId, $language, $classification),
            default => null, // copilot_context: the decision row IS the delivery
        };
    }
}
