<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Honesty\FirstGreeting;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Pipeline\FlowStateProvider;
use App\Domain\Ai\Pipeline\RoutePolicy;
use App\Domain\Ai\Pipeline\Thresholds;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Providers\RefusalDetector;
use App\Domain\Ai\Quota\AiQuotaExhausted;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Kb\Tombstones\CitationLinks;
use App\Models\Conversation;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Stringable;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * FR-08/FR-09/FR-12 + EC-02/04/05/08/11/12 + FS-01/FS-02: THE CHATBOT TURN.
 *
 * Order of the guards, each BEFORE any model call and each a handoff reason
 * from the closed list: RoutePolicy (spam stops; low confidence hands off);
 * EC-08's post-resolution loop (×2 → post_resolution_loop); the
 * max_ai_messages guardrail (message_limit); EC-04's blocked-topic
 * pre-screen (deflection TEMPLATE, third strike → blocked_topic_persistence);
 * EC-02's unsupported language (no KB coverage outside en/am → the
 * capability template + unsupported_language). Then: typing (Doc 05 FR-19
 * via the adapter capability), context (the SEC-04 allowlist builder),
 * retrieval (hybrid; FS-02 degrades to no-citation = no answer), assembly
 * (the STEP-0-proven strict template with the forms list at the TAIL and the
 * flow state), the model, EC-05's protocol (discard → ONE repair retry →
 * invalid_action handoff, both logged), EC-11 (provider_refusal), the
 * OutputGuard (leak → invalid_action), FS-01 (all exhausted → degraded
 * handoff within this turn), EC-12 (quota_exhausted handoff).
 *
 * Every path writes its decision row. §21 rec 2's conservative launch: the
 * strong tier runs at the catalog's temperature; the golden + nightly
 * corpus is the guard on every prompt change.
 */
final class ChatbotTurn
{
    public const TEMPLATE = <<<'TPL'
        Decide the next chatbot action for the customer's latest message. Return ONLY a JSON object, no prose, no markdown fences. It must be EXACTLY ONE of:
        {"action":"answer","text":"...","citations":["<article_ref>"]}
        {"action":"run_form","form_id":"..."}
        {"action":"continue_flow","state":"..."}
        {"action":"handoff","reason":"<one of: low_confidence|customer_request|blocked_intent|form_route|no_grounding|message_limit|post_resolution_loop|invalid_action|blocked_topic_persistence|unsupported_language|provider_refusal|rg_risk|quota_exhausted|degraded>"}
        {"action":"resolve","closing_text":"..."}
        Citations may name ONLY article_refs present in the retrieved-knowledge block. With no citation available for a money/policy answer you must handoff with reason "no_grounding". Never state account or transaction facts as facts — restate claims as claims. When the customer asks for a human, handoff with customer_request. When the customer confirms their issue is solved, resolve.
        TPL;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ContextBuilder $contextBuilder,
        private readonly LayeredAssembly $assembly,
        private readonly AiOrchestrator $orchestrator,
        private readonly ActionSchema $schema,
        private readonly OutputGuard $outputGuard,
        private readonly RefusalDetector $refusals,
        private readonly HybridRetriever $retriever,
        private readonly ComposeOutbound $outbound,
        private readonly HandoffDecision $handoffs,
        private readonly DecisionWriter $decisions,
        private readonly EventPublisher $events,
        private readonly Thresholds $thresholds,
        private readonly ConfigVersions $configs,
        private readonly BlockedTopics $blockedTopics,
        private readonly FlowStateProvider $flows,
        private readonly CitationLinks $citationLinks, // S10 step 7b packet P2 (countersigned): version-pinned citation URLs
        private readonly AdapterRegistry $adapters,
        private readonly SettingsRepository $settings,
        private readonly FirstGreeting $greeting,
        private readonly \App\Domain\Ai\Language\LanguageGate $languageGate,
    ) {}

    /** @param array<string, mixed> $classification
     * @return array{action: string, reason?: string, decision_id: string} */
    public function run(string $classificationDecisionId, string $conversationId, string $messageId, string $language, array $classification): array
    {
        $conversation = Conversation::query()->findOrFail($conversationId);
        $message = DB::table('messages')->where('id', $messageId)->first();
        $text = is_object($message) && is_string($message->body) ? $message->body : '';
        $endpointRef = $conversation->getAttribute('channel_ref_id');
        $endpointRef = is_string($endpointRef) ? $endpointRef : null;
        $customerId = self::str($conversation->getAttribute('customer_id'));

        $thresholds = [
            'rg_min' => $this->thresholds->value('rg_min', $endpointRef),
            'spam_min' => $this->thresholds->value('spam_min', $endpointRef),
            'handoff_below' => $this->thresholds->value('handoff_below', $endpointRef),
            'auto_resolve_min' => $this->thresholds->value('auto_resolve_min', $endpointRef),
        ];
        $policy = RoutePolicy::outcomeFor(
            is_numeric($classification['confidence'] ?? null) ? (float) $classification['confidence'] : 0.7,
            is_numeric($classification['rg_risk'] ?? null) ? (float) $classification['rg_risk'] : 0.0,
            is_numeric($classification['spam'] ?? null) ? (float) $classification['spam'] : 0.0,
            $thresholds,
        );

        // ── Pre-call guards, each a closed reason ──────────────────────────
        if ($policy === 'spam') {
            return $this->recordOnly($classificationDecisionId, $conversation, $messageId, $language, $classification, 'spam_stop');
        }
        if ($policy === 'handoff_low_confidence') {
            return $this->handoffOut('low_confidence', $classificationDecisionId, $conversation, $messageId, $language, $classification);
        }

        $postResolution = $this->postResolutionCount($conversationId);
        if ($postResolution >= 2) {
            return $this->handoffOut('post_resolution_loop', $classificationDecisionId, $conversation, $messageId, $language, $classification);
        }

        if ($this->aiMessageCount($conversationId) >= $this->maxAiMessages()) {
            return $this->handoffOut('message_limit', $classificationDecisionId, $conversation, $messageId, $language, $classification);
        }

        if (($topic = $this->blockedTopics->matches($text)) !== null) {
            if ($this->deflectionCount($conversationId) >= 2) {
                return $this->handoffOut('blocked_topic_persistence', $classificationDecisionId, $conversation, $messageId, $language, $classification);
            }
            $template = __('ai.deflection.template', [], $language);
            $this->outbound->compose($conversation, 'ai', null, 'text', $this->greeting->prefixIfFirst($conversationId, $language, is_string($template) ? $template : (string) __('ai.deflection.template', [], 'en')), 'deflect:'.$classificationDecisionId);

            return $this->recordOnly($classificationDecisionId, $conversation, $messageId, $language, $classification, 'deflect', ['blocked_topic' => $topic]);
        }

        if (! in_array($language, $this->languageGate->enabledFor($endpointRef), true)) {
            // EC-02 + §14: a language this endpoint does not speak (the launch
            // pair by default; a third language only once its templates exist
            // and the record enables it) — the capability template (English
            // fallback) + unsupported_language handoff.
            $template = __('ai.unsupported_language.template', [], 'en');
            $this->outbound->compose($conversation, 'ai', null, 'text', $this->greeting->prefixIfFirst($conversationId, 'en', (string) $template), 'unsupported:'.$classificationDecisionId);

            return $this->handoffOut('unsupported_language', $classificationDecisionId, $conversation, $messageId, $language, $classification, sendTemplate: false);
        }

        // ── Typing (Doc 05 FR-19 via the adapter capability), best-effort ──
        $this->typing($conversation);

        // ── Context → retrieval → assembly ───────────────────────────────
        $window = $this->window($conversationId, $postResolution > 0);
        $context = $this->contextBuilder->build($customerId, $language, $window);
        if ($postResolution > 0) {
            $context['prior_topics'][] = 'post_resolution: the customer replied after an AI resolve — greet with continuity';
        }

        $retrieval = ['chunks' => [], 'degraded' => false];
        try {
            $retrieval = $this->retriever->retrieve($text, array_values(array_unique([$language, 'en'])));
        } catch (Throwable) {
            $retrieval = ['chunks' => [], 'degraded' => true]; // FS-02: no grounding → the schema refuses uncited answers
        }
        $allowedRefs = array_values(array_unique(array_map(static fn (array $c): string => $c['article_ref'], $retrieval['chunks'])));

        $flowState = $this->flows->activeState($conversationId);
        $forms = $this->flows->availableForms($endpointRef);
        $template = self::TEMPLATE
            ."\nAvailable forms (run_form may name ONLY these): ".($forms === [] ? 'none' : implode(', ', $forms)).'.'
            .($flowState === null ? '' : "\nActive flow state: ".json_encode($flowState, JSON_UNESCAPED_UNICODE).' (continue_flow continues it).')
            ."\nJSON only — no prose.";

        $assembled = $this->assembly->assemble('chatbot_turn', $template, $context, array_map(
            static fn (array $c): array => ['article_ref' => $c['article_ref'], 'content' => $c['content']],
            $retrieval['chunks'],
        ));

        // ── The model, with EC-05's protocol ─────────────────────────────
        $started = microtime(true);
        $attempts = [];
        try {
            $result = $this->orchestrator->execute('chatbot_turn', $assembled['messages']);
            $attempts[] = $result;

            if ($this->refusals->isPolicyRefusal($result['response'])) {
                $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, null, 'provider_refusal', $started);

                return $this->handoffOut('provider_refusal', $classificationDecisionId, $conversation, $messageId, $language, $classification, $allowedRefs);
            }

            $validated = $this->schema->validate(json_decode((string) $result['response']->content, true), $allowedRefs);

            if ($validated instanceof DiscardedAction) {
                // EC-05: ONE repair retry with the repair instruction, both logged.
                $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, null, 'invalid_action', $started, ['discard' => $validated->detail, 'attempt' => 1]);
                $repair = $assembled['messages'];
                $repair[] = ['role' => 'system', 'content' => 'Your previous output was invalid: '.$validated->detail.'. Return ONLY a valid JSON object per the schema — nothing else.'];
                $result = $this->orchestrator->execute('chatbot_turn', $repair);
                $attempts[] = $result;
                $validated = $this->schema->validate(json_decode((string) $result['response']->content, true), $allowedRefs);

                if ($validated instanceof DiscardedAction) {
                    $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, null, 'invalid_action', $started, ['discard' => $validated->detail, 'attempt' => 2]);
                    $reason = str_contains($validated->detail, 'citation') ? 'no_grounding' : 'invalid_action';

                    return $this->handoffOut($reason, $classificationDecisionId, $conversation, $messageId, $language, $classification, $allowedRefs);
                }
            }
        } catch (AllProvidersExhausted) {
            // FS-01: bypass-to-handoff within THIS turn — the customer never waits on a dead provider.
            return $this->handoffOut('degraded', $classificationDecisionId, $conversation, $messageId, $language, $classification, $allowedRefs);
        } catch (AiQuotaExhausted) {
            return $this->handoffOut('quota_exhausted', $classificationDecisionId, $conversation, $messageId, $language, $classification, $allowedRefs);
        }

        // ── Execute the validated action ─────────────────────────────────
        $action = $validated->action;
        $payload = $validated->payload;

        if ($action === 'answer') {
            $answerText = is_string($payload['text'] ?? null) ? $payload['text'] : '';
            if ($this->outputGuard->leaksSystemText($answerText)) {
                $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, 'answer', 'invalid_action', $started, ['leak' => true]);

                return $this->handoffOut('invalid_action', $classificationDecisionId, $conversation, $messageId, $language, $classification, $allowedRefs);
            }
            $citations = is_array($payload['citations'] ?? null) ? array_values(array_filter($payload['citations'], 'is_string')) : [];
            $this->outbound->compose($conversation, 'ai', null, 'text', $this->greeting->prefixIfFirst($conversationId, $language, $this->renderAnswer($conversation, $answerText, $citations)), 'answer:'.$classificationDecisionId);
            $decisionId = $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, 'answer', null, $started, ['citations' => $citations, 'kb_shown' => $citations]);
            $this->citedByAi($citations, $conversationId, $decisionId, $language);

            return ['action' => 'answer', 'decision_id' => $decisionId];
        }

        if ($action === 'resolve') {
            $closing = is_string($payload['closing_text'] ?? null) ? $payload['closing_text'] : '';
            $this->outbound->compose($conversation, 'ai', null, 'text', $this->greeting->prefixIfFirst($conversationId, $language, $closing), 'resolve:'.$classificationDecisionId);
            $decisionId = $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, 'resolve', null, $started, ['csat_prompt' => '[D12] seam']);
            DB::transaction(fn () => $this->events->publish('ai.resolved', [
                'tenant_id' => $this->context->tenantId(),
                'conversation_id' => $conversationId,
                'decision_id' => $decisionId,
            ]));

            return ['action' => 'resolve', 'decision_id' => $decisionId];
        }

        if ($action === 'handoff') {
            $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : 'low_confidence';
            $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, 'handoff', null, $started, ['model_reason' => $reason]);

            return $this->handoffOut($reason, $classificationDecisionId, $conversation, $messageId, $language, $classification, $allowedRefs);
        }

        // run_form ([D12] seam) / continue_flow (Doc 05 seam): recorded, the runner is theirs.
        $decisionId = $this->log($classificationDecisionId, $conversation, $messageId, $language, $classification, $assembled, $result, $action, null, $started, ['payload' => $payload]);

        return ['action' => $action, 'decision_id' => $decisionId];
    }

    /**
     * @param  array<string, mixed>  $classification
     * @param  list<string>  $kbShown
     * @return array{action: string, reason: string, decision_id: string}
     */
    private function handoffOut(string $reason, string $triggerDecisionId, Conversation $conversation, string $messageId, string $language, array $classification, array $kbShown = [], bool $sendTemplate = true): array
    {
        $handoff = $this->handoffs->handoff($reason, $triggerDecisionId, $conversation, $messageId, $language, $classification, $kbShown, null, $sendTemplate);

        return ['action' => 'handoff', 'reason' => $reason, 'decision_id' => $handoff['decision_id']];
    }

    /**
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $meta
     * @return array{action: string, decision_id: string}
     */
    private function recordOnly(string $triggerDecisionId, Conversation $conversation, string $messageId, string $language, array $classification, string $action, array $meta = []): array
    {
        $decisionId = (string) new UuidV7;
        $endpointRef = $conversation->getAttribute('channel_ref_id');
        $this->decisions->record(new DecisionRecord(
            id: $decisionId, surface: 'chatbot', task: 'chatbot_turn', provider: 'none', model: 'none',
            layerHashes: ['immutable' => \App\Domain\Ai\Prompt\ImmutableLayer::hash()],
            conversationId: $conversation->identifier(), customerId: self::str($conversation->getAttribute('customer_id')),
            messageId: $messageId, endpointRef: is_string($endpointRef) ? $endpointRef : null, language: $language,
            intent: is_string($classification['intent'] ?? null) ? $classification['intent'] : null,
            action: $action, responsePayload: $meta + ['trigger_decision_id' => $triggerDecisionId],
        ));
        $this->decisions->flush();

        return ['action' => $action, 'decision_id' => $decisionId];
    }

    /**
     * @param  array<string, mixed>  $classification
     * @param  array{messages: list<array{role: string, content: string}>, layer_hashes: array<string, string>, masking_map: array<string, string>}  $assembled
     * @param  array{response: \App\Domain\Ai\Providers\ProviderResponse, provider: string, model: string, latency_ms: int}  $result
     * @param  array<string, mixed>  $meta
     */
    private function log(string $triggerDecisionId, Conversation $conversation, string $messageId, string $language, array $classification, array $assembled, array $result, ?string $action, ?string $errorCategory, float $started, array $meta = []): string
    {
        $decisionId = (string) new UuidV7;
        $endpointRef = $conversation->getAttribute('channel_ref_id');
        $this->decisions->record(new DecisionRecord(
            id: $decisionId, surface: 'chatbot', task: 'chatbot_turn',
            provider: $result['provider'], model: $result['model'],
            layerHashes: $assembled['layer_hashes'],
            conversationId: $conversation->identifier(), customerId: self::str($conversation->getAttribute('customer_id')),
            messageId: $messageId, endpointRef: is_string($endpointRef) ? $endpointRef : null, language: $language,
            intent: is_string($classification['intent'] ?? null) ? $classification['intent'] : null,
            confidence: is_numeric($classification['confidence'] ?? null) ? (float) $classification['confidence'] : null,
            action: $action, rgRisk: is_numeric($classification['rg_risk'] ?? null) ? (float) $classification['rg_risk'] : null,
            tokensIn: $result['response']->tokensIn, tokensOut: $result['response']->tokensOut,
            latencyMs: (int) round((microtime(true) - $started) * 1000), errorCategory: $errorCategory,
            requestPayload: ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']],
            responsePayload: $meta + ['content' => $result['response']->content, 'trigger_decision_id' => $triggerDecisionId],
        ));
        $this->decisions->flush();

        return $decisionId;
    }

    /**
     * S10 packet P2 (Doc 10 FR-12): kb.article.cited_by_ai per Doc 10 article cited — identifiers
     * only, through the outbox in a transaction; refs with no article row (fixtures) emit nothing;
     * no citation → no event (the negative control).
     *
     * @param  list<string>  $citations
     */
    private function citedByAi(array $citations, string $conversationId, string $decisionId, string $language): void
    {
        foreach ($citations as $ref) {
            $link = $this->citationLinks->forArticle($ref, $language);
            if ($link === null) {
                continue;
            }
            DB::transaction(fn () => $this->events->publish('kb.article.cited_by_ai', [
                'tenant_id' => $this->context->tenantId(), 'article_id' => $ref, 'version_id' => $link['version_id'],
                'conversation_id' => $conversationId, 'decision_id' => $decisionId,
            ]));
        }
    }

    /**
     * FR-27/T-04: citations render per channel capability — trailing links when no buttons.
     *
     * @param  list<string>  $citations
     */
    private function renderAnswer(Conversation $conversation, string $text, array $citations): string
    {
        if ($citations === []) {
            return $text;
        }
        // S10 packet P2 (Doc 10 FR-14/T-06): a Doc 10 article renders as its version-pinned
        // URL; a ref with no article row (the golden fixtures) keeps the bare ref — the control.
        $language = is_string($conversation->getAttribute('language')) ? $conversation->getAttribute('language') : null;
        $lines = array_map(function (string $ref) use ($language): string {
            $link = $this->citationLinks->forArticle($ref, $language);

            return '📄 '.($link === null ? $ref : $link['url']);
        }, $citations);

        return $text."\n\n".implode("\n", $lines);
    }

    private function typing(Conversation $conversation): void
    {
        try {
            $adapter = $this->adapters->adapter(self::str($conversation->getAttribute('channel')));
            if ($adapter instanceof SupportsTyping && $adapter->capabilities()->supportsTyping) {
                $adapter->sendTyping(self::str($conversation->getAttribute('external_chat_id')));
            }
        } catch (Throwable) {
            // typing is a courtesy, never a dependency
        }
    }

    /** @return list<array{role: string, content: string, kind?: string}> */
    private function window(string $conversationId, bool $postResolution): array
    {
        $n = $this->settings->get('ai.chatbot.window_messages');
        $n = is_int($n) ? $n : 12;
        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->whereIn('author_type', ['customer', 'ai'])
            ->orderByDesc('created_at')
            ->limit($n)
            ->get()
            ->reverse();

        $window = [];
        foreach ($rows as $row) {
            if (! is_string($row->body) || $row->body === '') {
                continue;
            }
            $window[] = ['role' => $row->author_type === 'ai' ? 'assistant' : 'user', 'content' => $row->body, 'kind' => self::str($row->kind)];
        }

        return $window;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }

    private function postResolutionCount(string $conversationId): int
    {
        return (int) DB::table('ai_decisions')
            ->where('conversation_id', $conversationId)
            ->where('task', 'chatbot_turn')
            ->where('action', 'resolve')
            ->whereRaw("created_at > clock_timestamp() - interval '24 hours'")
            ->count();
    }

    private function aiMessageCount(string $conversationId): int
    {
        return (int) DB::table('messages')->where('conversation_id', $conversationId)->where('author_type', 'ai')->where('direction', 'out')->count();
    }

    private function deflectionCount(string $conversationId): int
    {
        return (int) DB::table('ai_decisions')->where('conversation_id', $conversationId)->where('action', 'deflect')->count();
    }

    private function maxAiMessages(): int
    {
        $guardrails = $this->configs->published('guardrails', 'default');
        if ($guardrails !== null) {
            $decoded = json_decode($guardrails->payload, true);
            if (is_array($decoded) && is_numeric($decoded['max_ai_messages'] ?? null)) {
                return max(1, (int) $decoded['max_ai_messages']);
            }
        }

        return 8; // FR-12's default
    }
}
