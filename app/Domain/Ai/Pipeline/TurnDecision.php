<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

use Illuminate\Support\Facades\DB;

/**
 * FR-07's DECIDE stage, as pure logic over facts:
 *
 *   rg_risk >= rg_min           -> rg_override   (FR-13 — outranks EVERYTHING,
 *                                                 even mid-form; step 7 executes)
 *   open assigned ticket        -> copilot_context (classification delivered,
 *                                                 no chatbot turn)
 *   chatbot enabled here        -> chatbot_turn  (step 9 executes)
 *   otherwise                   -> handoff_path
 *
 * Enablement consults the ChatbotEnablement seam — step 8's kill switches +
 * endpoint flags implement it; the step-6 default is DISABLED EVERYWHERE
 * (the chatbot must not be reachable before its constraints exist — the
 * approved plan's safety ordering as a default, not a habit).
 */
final class TurnDecision
{
    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly ChatbotEnablement $enablement,
    ) {}

    /** @return 'rg_override'|'copilot_context'|'chatbot_turn'|'handoff_path' */
    public function decide(
        string $conversationId,
        string $channel,
        ?string $endpointRef,
        float $rgRisk,
    ): string {
        if ($rgRisk >= $this->thresholds->value('rg_min', $endpointRef)) {
            return 'rg_override';
        }

        // FILED INTERPRETATION (S09 step 14, found by the NFR-02 perf leg):
        // FR-07 says "open ASSIGNED ticket -> Copilot context". Read literally,
        // an open UNASSIGNED ticket (the one the handoff path itself just
        // created, waiting in the queue) sent the customer's NEXT message down
        // the handoff path AGAIN -> createFromHandoff -> OpenTicketExists ->
        // the consumer crashed and retried on every further message (BR-05's
        // "transition it, do not duplicate" refusing correctly). ANY open
        // ticket means a human owns the conversation: no second handoff, no
        // chatbot turn — the classification rides to the Copilot, and the
        // queue's own assignment delivers it. Assignment is not the predicate;
        // an open ticket is.
        $openTicket = DB::table('tickets')
            ->where('conversation_id', $conversationId)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->exists();

        if ($openTicket) {
            return 'copilot_context';
        }

        if ($this->enablement->enabledFor('chatbot', $channel, $endpointRef)) {
            return 'chatbot_turn';
        }

        return 'handoff_path';
    }
}
