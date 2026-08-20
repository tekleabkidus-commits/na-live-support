<?php

declare(strict_types=1);

namespace App\Domain\Ai\Rg;

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Pipeline\Thresholds;
use App\Domain\Ai\Prompt\ImmutableLayer;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Customers\Rg\RgFlagService;
use App\Domain\Tickets\Creation\CreateFromHandoff;
use App\Domain\Tickets\Creation\HandoffPacket;
use App\Models\Conversation;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-13: THE RG OVERRIDE — rg_risk >= rg_min outranks everything (even
 * mid-form; FR-07's decide returns it FIRST). In ONE transaction:
 *
 *   1. the flag, through Doc 08's built path — RgFlagService::set(source:
 *      'ai', excerpt MANDATORY, actor NULL by CHECK): the [D09] FR-14 close.
 *      customer.rg_flag.set fires from inside that path, which is how
 *      FR-15's suppression contracts (Doc 12/14 consumers) ENGAGE;
 *   2. the OPERATOR-APPROVED TEMPLATE to the customer — BR-03/N-10: this
 *      class holds NO generation dependency (no orchestrator, no provider —
 *      the structural assertion in RgOverrideTest), the body is the
 *      template string BYTE FOR BYTE (AC-04's byte-verify; sabotage #6);
 *   3. the handoff to the RG queue CONTRACT via Doc 06's createFromHandoff
 *      (the FR-11 packet; Doc 11 owns the live queue — [D11]);
 *   4. ai.rg.flagged + ai.handoff.created (FR-21) and the override's own
 *      decision row carrying ticket_id — T-08's enrichment join key
 *      (the Doc 06 packet contract stays untouched; see the checkpoint
 *      note on the literal "embeds decision_id").
 *
 * Template source: a published guardrails config (rg_template.<lang>)
 * overrides the platform-seeded lang key (wording is operator content —
 * FR-18; presence is not — BR-05's shape applied to RG).
 */
final class RgOverride
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RgFlagService $flags,
        private readonly ComposeOutbound $outbound,
        private readonly CreateFromHandoff $handoff,
        private readonly DecisionWriter $decisions,
        private readonly EventPublisher $events,
        private readonly Thresholds $thresholds,
        private readonly ConfigVersions $configs,
    ) {}

    /** The template the customer will receive, byte for byte (AC-04). */
    public function templateFor(string $language): string
    {
        $guardrails = $this->configs->published('guardrails', 'default');
        if ($guardrails !== null) {
            $decoded = json_decode($guardrails->payload, true);
            $templates = is_array($decoded) && is_array($decoded['rg_template'] ?? null) ? $decoded['rg_template'] : [];
            if (is_string($templates[$language] ?? null) && trim($templates[$language]) !== '') {
                return $templates[$language];
            }
        }

        $seeded = __('ai.rg.template', [], $language);

        return is_string($seeded) ? $seeded : (string) __('ai.rg.template', [], 'en');
    }

    /**
     * @param  array<string, mixed>  $classification
     * @return array{ticket_id: string, history_id: string, decision_id: string}
     */
    public function execute(string $classificationDecisionId, string $conversationId, string $messageId, string $language, array $classification): array
    {
        $rgRisk = is_numeric($classification['rg_risk'] ?? null) ? (float) $classification['rg_risk'] : 1.0;

        return DB::transaction(function () use ($classificationDecisionId, $conversationId, $messageId, $language, $classification, $rgRisk): array {
            $conversation = Conversation::query()->findOrFail($conversationId);
            $customerIdRaw = $conversation->getAttribute('customer_id');
            if (! is_string($customerIdRaw)) {
                throw new \RuntimeException('conversation without a customer — the RG override cannot flag nobody');
            }
            $customerId = $customerIdRaw;
            $endpointRef = $conversation->getAttribute('channel_ref_id');
            $threshold = $this->thresholds->value('rg_min', is_string($endpointRef) ? $endpointRef : null);

            // 1. The flag through Doc 08's door — excerpt MANDATORY, actor NULL.
            $historyId = $this->flags->set(
                $customerId,
                sprintf('ai_rg_detection: score %.2f at rg_min %.2f (decision %s)', $rgRisk, $threshold, $classificationDecisionId),
                'ai',
                null,
                ['message_id' => $messageId, 'conversation_id' => $conversationId, 'decision_id' => $classificationDecisionId],
            );

            // 2. The template, byte for byte — never generated (BR-03).
            $template = $this->templateFor($language);
            $this->outbound->compose($conversation, 'ai', null, 'text', $template, 'rg-override:'.$classificationDecisionId);

            // 3. The handoff to the RG queue contract (Doc 06's door; Doc 11's queue).
            $ticket = $this->handoff->create($conversation, new HandoffPacket(
                summary: 'The assistant detected responsible-gaming risk signals in this conversation. The customer received the responsible-gaming template, not a generated reply. Routing: the responsible-gaming queue. A specialist should review before any further contact.',
                intent: 'rg_risk',
                confidence: max(0.0, min(1.0, $rgRisk)),
                language: $language,
                sentiment: (string) (is_numeric($classification['sentiment'] ?? null) ? (int) $classification['sentiment'] : 0),
                urgency: (string) (is_numeric($classification['urgency'] ?? null) ? (int) $classification['urgency'] : 3),
                suggestedDepartment: null,
                suggestedPriority: 'urgent',
                suggestedReasons: ['rg_risk', 'rg_queue'],
                kbShown: [],
                transcriptAnchor: ['from_message_id' => $messageId, 'to_message_id' => $messageId],
                formData: null,
            ), 'rg:'.$classificationDecisionId);

            // 4. The override's own decision row — ticket_id is T-08's join key.
            $decisionId = (string) new UuidV7;
            $this->decisions->record(new DecisionRecord(
                id: $decisionId,
                surface: 'chatbot',
                task: 'classify_message',
                provider: 'none',
                model: 'none',
                layerHashes: ['immutable' => ImmutableLayer::hash()],
                conversationId: $conversationId,
                ticketId: $ticket->identifier(),
                customerId: $customerId,
                messageId: $messageId,
                endpointRef: is_string($endpointRef) ? $endpointRef : null,
                language: $language,
                intent: 'rg_risk',
                confidence: $rgRisk,
                action: 'handoff',
                rgRisk: $rgRisk,
                responsePayload: ['rg_override' => true, 'template_language' => $language, 'classification_decision_id' => $classificationDecisionId],
            ));
            $this->decisions->flush();

            $this->events->publish('ai.rg.flagged', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'conversation_id' => $conversationId,
                'decision_id' => $decisionId,
                'history_id' => $historyId,
            ]);
            $this->events->publish('ai.handoff.created', [
                'tenant_id' => $this->context->tenantId(),
                'conversation_id' => $conversationId,
                'ticket_id' => $ticket->identifier(),
                'decision_id' => $decisionId,
                'reason' => 'rg_risk',
            ]);

            return ['ticket_id' => $ticket->identifier(), 'history_id' => $historyId, 'decision_id' => $decisionId];
        });
    }
}
