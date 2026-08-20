<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/**
 * The FR-07 route's execution seam. Steps 7 (rg_override) and 9
 * (chatbot_turn, handoff_path) bind their executors here; copilot_context
 * is a no-act route (the decision row is the delivery). The step-6 default
 * executes NOTHING — classification lands, routes wait for their owners.
 *
 * @param  array{intent: string, confidence: float, sentiment: int, urgency: int, spam: float, rg_risk: float}  $classification
 */
interface RouteExecutor
{
    /** @param array<string, mixed> $classification */
    public function execute(string $route, string $decisionId, string $conversationId, string $messageId, string $language, array $classification): void;
}
