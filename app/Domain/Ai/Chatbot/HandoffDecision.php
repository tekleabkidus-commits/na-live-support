<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\ImmutableLayer;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Tickets\Creation\CreateFromHandoff;
use App\Domain\Tickets\Creation\HandoffPacket;
use App\Models\Conversation;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * FR-12: THE HANDOFF — produces Doc 06's FR-11 packet EXACTLY and calls
 * createFromHandoff (Doc 06's door, consumed); sends the FR-28 handoff
 * template to the customer (the seeded ai.handoff.template per language;
 * Doc 05's per-bot TemplateResolver wording when the endpoint carries one —
 * [D11]'s live wait estimate joins that template when Doc 11 lands); writes
 * the decision row carrying ticket_id (T-08's join key — the countersigned
 * item-3 shape); emits ai.handoff.created (FR-21).
 *
 * The packet's 2–4-sentence summary comes from the summarize_thread task
 * (fast tier) with a TEMPLATED fallback when generation is unavailable — a
 * handoff never waits on a model (FS-01: within one turn).
 */
final class HandoffDecision
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiOrchestrator $orchestrator,
        private readonly CreateFromHandoff $handoff,
        private readonly ComposeOutbound $outbound,
        private readonly DecisionWriter $decisions,
        private readonly EventPublisher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $classification
     * @param  list<string>  $kbShown
     * @param  array<string, mixed>|null  $formData
     * @return array{ticket_id: string, decision_id: string}
     */
    public function handoff(
        string $reason,
        string $triggerDecisionId,
        Conversation $conversation,
        string $messageId,
        string $language,
        array $classification,
        array $kbShown = [],
        ?array $formData = null,
        bool $sendTemplate = true,
    ): array {
        if (! in_array($reason, ActionSchema::HANDOFF_REASONS, true)) {
            $reason = 'low_confidence';
        }

        $summary = $this->summary($conversation, $language, $reason);
        $confidence = is_numeric($classification['confidence'] ?? null) ? max(0.0, min(1.0, (float) $classification['confidence'])) : 0.5;

        return DB::transaction(function () use ($reason, $triggerDecisionId, $conversation, $messageId, $language, $classification, $kbShown, $formData, $sendTemplate, $summary, $confidence): array {
            $ticket = $this->handoff->create($conversation, new HandoffPacket(
                summary: $summary,
                intent: is_string($classification['intent'] ?? null) ? $classification['intent'] : 'other',
                confidence: $confidence,
                language: $language,
                sentiment: (string) (is_numeric($classification['sentiment'] ?? null) ? (int) $classification['sentiment'] : 0),
                urgency: (string) (is_numeric($classification['urgency'] ?? null) ? (int) $classification['urgency'] : 1),
                suggestedDepartment: null,
                suggestedPriority: (is_numeric($classification['urgency'] ?? null) ? (int) $classification['urgency'] : 1) >= 3 ? 'high' : 'normal',
                suggestedReasons: [$reason],
                kbShown: $kbShown,
                transcriptAnchor: ['from_message_id' => $messageId, 'to_message_id' => $messageId],
                formData: $formData,
            ), 'handoff:'.$triggerDecisionId);

            if ($sendTemplate) {
                $template = __('ai.handoff.template', [], $language);
                $this->outbound->compose($conversation, 'ai', null, 'text', is_string($template) ? $template : (string) __('ai.handoff.template', [], 'en'), 'handoff:'.$triggerDecisionId);
            }

            $decisionId = (string) new UuidV7;
            $endpointRef = $conversation->getAttribute('channel_ref_id');
            $customerId = $conversation->getAttribute('customer_id');
            $this->decisions->record(new DecisionRecord(
                id: $decisionId,
                surface: 'chatbot',
                task: 'chatbot_turn',
                provider: 'none',
                model: 'none',
                layerHashes: ['immutable' => ImmutableLayer::hash()],
                conversationId: $conversation->identifier(),
                ticketId: $ticket->identifier(),
                customerId: is_string($customerId) ? $customerId : null,
                messageId: $messageId,
                endpointRef: is_string($endpointRef) ? $endpointRef : null,
                language: $language,
                intent: is_string($classification['intent'] ?? null) ? $classification['intent'] : null,
                confidence: $confidence,
                action: 'handoff',
                responsePayload: ['handoff_reason' => $reason, 'trigger_decision_id' => $triggerDecisionId],
            ));
            $this->decisions->flush();

            $this->events->publish('ai.handoff.created', [
                'tenant_id' => $this->context->tenantId(),
                'conversation_id' => $conversation->identifier(),
                'ticket_id' => $ticket->identifier(),
                'decision_id' => $decisionId,
                'reason' => $reason,
            ]);

            return ['ticket_id' => $ticket->identifier(), 'decision_id' => $decisionId];
        });
    }

    private function summary(Conversation $conversation, string $language, string $reason): string
    {
        $recent = DB::table('messages')
            ->where('conversation_id', $conversation->identifier())
            ->where('kind', '!=', 'note')
            ->orderByDesc('created_at')
            ->limit(8)
            ->pluck('body')
            ->filter(static fn (mixed $body): bool => is_string($body) && trim($body) !== '')
            ->reverse()
            ->implode("\n");

        try {
            $result = $this->orchestrator->execute('summarize_thread', [
                ['role' => 'system', 'content' => 'Summarize this customer-support conversation for the human agent taking over in exactly 3 sentences: what the customer wants, what has happened so far, what remains open. Never state account or transaction facts as facts. Plain prose, no lists.'],
                ['role' => 'user', 'content' => $recent],
            ]);
            $summary = trim((string) $result['response']->content);
            if (preg_match_all('/[.!?](\s|$)/u', $summary) >= 2 && preg_match_all('/[.!?](\s|$)/u', $summary) <= 4) {
                return $summary;
            }
        } catch (Throwable) {
            // fall through to the template
        }

        return sprintf(
            'The assistant handed this conversation to a human (reason: %s). The customer wrote in %s and the last messages are anchored to this ticket. Review the transcript before replying.',
            $reason,
            $language,
        );
    }
}
