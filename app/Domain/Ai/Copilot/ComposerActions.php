<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Ai\Chatbot\ContextBuilder;
use App\Domain\Ai\Chatbot\OutputGuard;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Pipeline\Thresholds;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Prompt\PiiRedaction;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Providers\RefusalDetector;
use App\Domain\Ai\Quota\AiQuotaExhausted;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Conversations\Messages\LanguageDetector;
use App\Models\Conversation;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Stringable;
use Throwable;

/**
 * FR-14's suggested reply + FR-15's four composer actions + EC-09 + EC-10 +
 * §14's digit rule. Every call returns a CARD (the shape the panel renders
 * and the relay pushes) and mints its artifact row (FR-16) — there is no
 * path out of this class except the returned array: no ComposeOutbound,
 * no adapter, no lane (BR-01 — CopilotNeverAutoSendTest asserts the
 * ABSENCE statically and the runtime proof counts zero outbound rows).
 *
 * States: artifact (a draft the agent may insert) · low_confidence (§8:
 * the banner + KB links INSTEAD of a bad draft) · locked (EC-09: three
 * regenerations spent — low-confidence state, telemetry row for the
 * weekly review) · cooldown (EC-09's regenerate cooldown) · refused (§14
 * digit mismatch after the repair retry; provider refusal; leak) ·
 * degraded (FS-01's Copilot card: provider chain exhausted / quota).
 *
 * SEC-02 on staff text: improve/translate mask the agent's own text for
 * egress and UNMASK positionally on the way back — the provider sees
 * [masked:phone-1], the agent sees the number they typed. Drafts built
 * from the customer window keep assembly's labels (the thread shows the
 * values; the agent edits) — stated, not hidden.
 */
final class ComposerActions
{
    public const REGENERATE_LIMIT = 3;

    public const DRAFT_TEMPLATE = <<<'TPL'
        Draft a reply the human support AGENT can send to the customer, written in the customer's language. Use ONLY the retrieved knowledge for any policy, fee, bonus, limit or timing statement and list the article_refs you relied on. Never state account or transaction facts as facts — restate the customer's claim as a claim and say what the agent will check. Be concise and warm. Return ONLY a JSON object, no prose, no markdown fences:
        {"text":"<the draft>","citations":["<article_ref>"],"confidence":<0..1 how confident you are this draft is correct and complete>}
        TPL;

    public const IMPROVE_TEMPLATE = <<<'TPL'
        Improve the AGENT's draft below for tone and grammar while keeping its meaning, language and every number, amount, date, reference code and name EXACTLY as written. Do not add or remove facts. Return ONLY the improved text.
        TPL;

    public const TRANSLATE_TEMPLATE = <<<'TPL'
        Translate the AGENT's draft below into %s (%s). Preserve every number, currency amount, date, time, reference code and account identifier EXACTLY as written — never convert, round, localize or reorder digits. Keep embedded product names and technical terms that appear in another language verbatim. Return ONLY the translation, nothing else.
        TPL;

    public const SUMMARIZE_TEMPLATE = <<<'TPL'
        Summarize this conversation for an INTERNAL staff note in 3 to 5 sentences: the customer's issue stated as their claim, what has been done so far, and what remains open. No greetings, no advice to the customer. Return ONLY the summary.
        TPL;

    private const LANGUAGE_NAMES = ['en' => 'English', 'am' => 'Amharic'];

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly LayeredAssembly $assembly,
        private readonly ContextBuilder $contextBuilder,
        private readonly HybridRetriever $retriever,
        private readonly CopilotArtifacts $artifacts,
        private readonly Thresholds $thresholds,
        private readonly PiiRedaction $redaction,
        private readonly LanguageDetector $languages,
        private readonly OutputGuard $outputGuard,
        private readonly RefusalDetector $refusals,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * FR-14's suggested reply / FR-15's Draft reply, with EC-09's regenerate
     * accounting per anchor message.
     *
     * @return array<string, mixed>
     */
    public function draft(string $agentId, Conversation $conversation, ?CopilotStream $stream = null): array
    {
        $conversationId = $conversation->identifier();
        $anchor = $this->latestCustomerMessage($conversationId);
        $anchorId = $anchor['id'];
        $language = $anchor['language'] ?? $this->customerLanguage($conversation);
        $endpointRef = self::str($conversation->getAttribute('channel_ref_id'));
        $endpointRef = $endpointRef === '' ? null : $endpointRef;

        // ── EC-09: regenerate ×3 per anchor, cooldown between ─────────────
        $prior = DB::table('ai_decisions')
            ->where('conversation_id', $conversationId)
            ->where('surface', 'copilot')
            ->where('task', 'draft_reply')
            ->when($anchorId !== null, fn ($q) => $q->where('message_id', $anchorId))
            ->whereIn('action', ['artifact', 'refused', 'low_confidence'])
            // A DEGRADED attempt (provider down / quota) is the platform's
            // failure, not the agent's regeneration — it counts toward
            // neither the ×3 nor the cooldown.
            ->where(function (\Illuminate\Database\Query\Builder $q): void {
                $q->whereNull('error_category')->orWhereNotIn('error_category', ['provider_error', 'quota_exhausted']);
            })
            ->orderByDesc('created_at')
            ->get(['id', 'action', 'created_at']);

        if ($prior->count() >= self::REGENERATE_LIMIT) {
            $kb = $this->kbFor($conversation, $anchor['body'] ?? '', $language);
            $alreadyLocked = DB::table('ai_decisions')->where('conversation_id', $conversationId)->where('surface', 'copilot')
                ->where('action', 'low_confidence_locked')->when($anchorId !== null, fn ($q) => $q->where('message_id', $anchorId))->exists();
            if (! $alreadyLocked) {
                // Telemetry for FR-20's weekly unanswerable review: one row per anchor.
                $this->artifacts->mint('draft_reply', 'locked', $agentId, $conversationId, $anchorId, $language, null, 'none', 'none', [], 0, 0, 0, null, ['locked' => true], null, null, 'low_confidence_locked');
            }

            return ['state' => 'locked', 'kind' => 'draft_reply', 'notices' => ['ai.copilot.notice.regenerate_exhausted'], 'kb' => $kb, 'regenerations' => ['used' => $prior->count(), 'limit' => self::REGENERATE_LIMIT]];
        }

        $cooldown = $this->settings->get('ai.copilot.regenerate_cooldown_seconds');
        $cooldown = is_int($cooldown) ? $cooldown : (is_numeric($cooldown) ? (int) $cooldown : 5);
        if ($prior->isNotEmpty() && $cooldown > 0) {
            $lastCreatedAt = $prior->first()->created_at;
            $inCooldown = DB::selectOne(
                'SELECT (?::timestamptz > clock_timestamp() - make_interval(secs => ?)) AS hot',
                [self::str($lastCreatedAt), $cooldown],
            );
            if (is_object($inCooldown) && (bool) ($inCooldown->hot ?? false)) {
                return ['state' => 'cooldown', 'kind' => 'draft_reply', 'notices' => ['ai.copilot.notice.cooldown'], 'regenerations' => ['used' => $prior->count(), 'limit' => self::REGENERATE_LIMIT]];
            }
        }

        // ── Context → retrieval (internal articles are Copilot-visible) ───
        $window = $this->window($conversationId);
        $context = $this->contextBuilder->build(self::str($conversation->getAttribute('customer_id')), $language, $window);
        $query = $anchor['body'] ?? '';
        $retrieval = ['chunks' => [], 'degraded' => false];
        try {
            $retrieval = $this->retriever->retrieve($query, array_values(array_unique([$language, 'en'])), includeInternal: true, topK: 3);
        } catch (Throwable) {
            $retrieval = ['chunks' => [], 'degraded' => true];
        }
        $allowedRefs = array_values(array_unique(array_map(static fn (array $c): string => $c['article_ref'], $retrieval['chunks'])));
        $kb = array_map(static fn (array $c): array => ['article_ref' => $c['article_ref'], 'excerpt' => mb_substr($c['content'], 0, 160)], $retrieval['chunks']);

        $classification = $this->latestClassification($conversationId);
        $handoffBelow = $this->thresholds->value('handoff_below', $endpointRef);
        $notices = [];
        if ($retrieval['degraded']) {
            $notices[] = 'ai.copilot.notice.retrieval_degraded';
        }

        $assembled = $this->assembly->assemble('draft_reply', self::DRAFT_TEMPLATE, $context, array_map(
            static fn (array $c): array => ['article_ref' => $c['article_ref'], 'content' => $c['content']],
            $retrieval['chunks'],
        ));

        $started = microtime(true);
        try {
            $result = $this->call('draft_reply', $assembled['messages'], $stream);
        } catch (AllProvidersExhausted|AiQuotaExhausted $e) {
            return $this->degraded('draft_reply', $agentId, $conversationId, $anchorId, $language, $e, $kb);
        }

        if ($this->refusals->isPolicyRefusal($result['response'])) {
            $id = $this->artifacts->mint('draft_reply', 'draft_reply', $agentId, $conversationId, $anchorId, $language, null, $result['provider'], $result['model'], $assembled['layer_hashes'], $result['response']->tokensIn, $result['response']->tokensOut, self::ms($started), 'provider_refusal', [], ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']]);

            return ['state' => 'low_confidence', 'kind' => 'draft_reply', 'artifact_id' => $id, 'notices' => ['ai.copilot.notice.low_confidence'], 'kb' => $kb];
        }

        $parsed = self::parseDraft((string) $result['response']->content, $allowedRefs);
        if ($parsed === null) {
            // One repair retry (EC-05's posture on the staff side).
            $repair = $assembled['messages'];
            $repair[] = ['role' => 'system', 'content' => 'Your previous output was not the required JSON object. Return ONLY {"text":"...","citations":[...],"confidence":0..1}.'];
            try {
                $result = $this->call('draft_reply', $repair, $stream?->restartAnd());
            } catch (AllProvidersExhausted|AiQuotaExhausted $e) {
                return $this->degraded('draft_reply', $agentId, $conversationId, $anchorId, $language, $e, $kb);
            }
            $parsed = self::parseDraft((string) $result['response']->content, $allowedRefs);
        }

        $tokensIn = $result['response']->tokensIn;
        $tokensOut = $result['response']->tokensOut;
        $request = ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']];

        if ($parsed === null || $this->outputGuard->leaksSystemText($parsed['text'])) {
            $id = $this->artifacts->mint('draft_reply', 'draft_reply', $agentId, $conversationId, $anchorId, $language, null, $result['provider'], $result['model'], $assembled['layer_hashes'], $tokensIn, $tokensOut, self::ms($started), 'invalid_action', ['leak' => $parsed !== null], $request);

            return ['state' => 'low_confidence', 'kind' => 'draft_reply', 'artifact_id' => $id, 'notices' => ['ai.copilot.notice.low_confidence'], 'kb' => $kb];
        }

        // §8: the low-confidence BANNER replaces a bad draft — the model's
        // own confidence or the classification's below the handoff floor.
        // A money/policy intent drafted WITHOUT a citation is not suppressed
        // (the agent verifies, BR-02 binds the chatbot) — it carries FR-14's
        // guardrail notice "low confidence — verify the reference manually".
        $classConfidence = is_numeric($classification['confidence'] ?? null) ? (float) $classification['confidence'] : 1.0;
        $moneyIntent = in_array($classification['intent'] ?? null, ['deposits', 'withdrawals', 'bonuses', 'betting_rules'], true);
        $lowConfidence = $parsed['confidence'] < $handoffBelow || $classConfidence < $handoffBelow;

        if ($lowConfidence) {
            $id = $this->artifacts->mint('draft_reply', 'draft_reply', $agentId, $conversationId, $anchorId, $language, null, $result['provider'], $result['model'], $assembled['layer_hashes'], $tokensIn, $tokensOut, self::ms($started), null, ['low_confidence' => true, 'confidence' => $parsed['confidence'], 'citations' => $parsed['citations']], $request, null, 'low_confidence');

            return ['state' => 'low_confidence', 'kind' => 'draft_reply', 'artifact_id' => $id, 'notices' => array_merge($notices, ['ai.copilot.notice.low_confidence']), 'kb' => $kb, 'confidence' => $parsed['confidence']];
        }

        if ($moneyIntent && $parsed['citations'] === []) {
            $notices[] = 'ai.copilot.notice.verify_reference';
        }

        $id = $this->artifacts->mint('draft_reply', 'draft_reply', $agentId, $conversationId, $anchorId, $language, $parsed['text'], $result['provider'], $result['model'], $assembled['layer_hashes'], $tokensIn, $tokensOut, self::ms($started), null, ['confidence' => $parsed['confidence'], 'citations' => $parsed['citations']], $request);

        return [
            'state' => 'artifact', 'kind' => 'draft_reply', 'artifact_id' => $id, 'text' => $parsed['text'],
            'citations' => $parsed['citations'], 'confidence' => $parsed['confidence'], 'notices' => $notices, 'kb' => $kb,
            'regenerations' => ['used' => $prior->count() + 1, 'limit' => self::REGENERATE_LIMIT],
        ];
    }

    /**
     * FR-15 Improve: tone/grammar, meaning kept — the §14 digit rule applies
     * (an "improvement" that changes an amount is not one).
     *
     * @return array<string, mixed>
     */
    public function improve(string $agentId, Conversation $conversation, string $text, ?CopilotStream $stream = null): array
    {
        $language = $this->languages->detect($text) ?? $this->customerLanguage($conversation);

        return $this->staffTextTask('improve_text', 'improve_text', $agentId, $conversation, $text, $language, self::IMPROVE_TEMPLATE, [], $stream);
    }

    /**
     * FR-15 Translate (+ EC-10): the agent writes in THEIR language; the
     * dominant language of the input is detected (mixed content resolves by
     * script majority — Doc 04's detector, consumed), the target is the
     * customer's language unless given; embedded other-language terms are
     * preserved by instruction, digits by the validator.
     *
     * @return array<string, mixed>
     */
    public function translate(string $agentId, Conversation $conversation, string $text, ?string $targetLanguage = null, ?CopilotStream $stream = null): array
    {
        $source = $this->languages->detect($text) ?? 'en';
        $target = $targetLanguage ?? $this->customerLanguage($conversation);
        $template = sprintf(self::TRANSLATE_TEMPLATE, self::LANGUAGE_NAMES[$target] ?? $target, $target);

        return $this->staffTextTask('translate', 'translate', $agentId, $conversation, $text, $target, $template, [
            'source_language' => $source,
            'target_language' => $target,
            'original_text' => $text, // FR-15: the original rides with the artifact; the message-metadata copy is the composer's at send
        ], $stream);
    }

    /**
     * FR-15 Summarize thread → an INTERNAL NOTE DRAFT (kind note_draft). It
     * is a draft for the NOTE composer: this class cannot write a note any
     * more than it can send (NotesWriter is not a dependency either).
     *
     * @return array<string, mixed>
     */
    public function summarize(string $agentId, Conversation $conversation, ?CopilotStream $stream = null): array
    {
        $conversationId = $conversation->identifier();
        $language = $this->customerLanguage($conversation);
        $window = $this->window($conversationId, 40);
        $context = $this->contextBuilder->build(self::str($conversation->getAttribute('customer_id')), $language, $window);
        $assembled = $this->assembly->assemble('summarize_thread', self::SUMMARIZE_TEMPLATE, $context);

        $started = microtime(true);
        try {
            $result = $this->call('summarize_thread', $assembled['messages'], $stream);
        } catch (AllProvidersExhausted|AiQuotaExhausted $e) {
            return $this->degraded('summarize_thread', $agentId, $conversationId, null, $language, $e);
        }

        $summary = trim((string) $result['response']->content);
        $request = ['messages' => $assembled['messages'], 'masking_map' => $assembled['masking_map']];
        if ($summary === '' || $this->refusals->isPolicyRefusal($result['response']) || $this->outputGuard->leaksSystemText($summary)) {
            $id = $this->artifacts->mint('summarize_thread', 'note_draft', $agentId, $conversationId, null, $language, null, $result['provider'], $result['model'], $assembled['layer_hashes'], $result['response']->tokensIn, $result['response']->tokensOut, self::ms($started), 'invalid_action', [], $request);

            return ['state' => 'refused', 'kind' => 'note_draft', 'artifact_id' => $id, 'notices' => ['ai.copilot.notice.unavailable']];
        }

        $id = $this->artifacts->mint('summarize_thread', 'note_draft', $agentId, $conversationId, null, $language, $summary, $result['provider'], $result['model'], $assembled['layer_hashes'], $result['response']->tokensIn, $result['response']->tokensOut, self::ms($started), null, [], $request);

        return ['state' => 'artifact', 'kind' => 'note_draft', 'artifact_id' => $id, 'text' => $summary, 'notices' => []];
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * improve/translate share one spine: mask the agent's text (values kept
     * in this frame), assemble with the text as the single user turn,
     * call, unmask, run the §14 validator, ONE repair retry, refuse.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function staffTextTask(string $task, string $kind, string $agentId, Conversation $conversation, string $text, string $language, string $template, array $meta, ?CopilotStream $stream): array
    {
        $conversationId = $conversation->identifier();
        $masked = $this->redaction->maskWithValues($text);
        $context = [
            'language' => $language,
            'prior_topics' => [],
            'acquisition' => null,
            'window' => [['role' => 'user', 'content' => "AGENT DRAFT:\n".$masked['text']]],
        ];
        // keepReferences: the agent text is ALREADY masked above (values in
        // hand); assembly must not mask a second time with a blind map.
        $assembled = $this->assembly->assemble($task, $template, $context, [], keepReferences: true);
        $request = ['messages' => $assembled['messages'], 'masking_map' => $masked['map']];

        $started = microtime(true);
        try {
            $result = $this->call($task, $assembled['messages'], $stream);
        } catch (AllProvidersExhausted|AiQuotaExhausted $e) {
            return $this->degraded($task, $agentId, $conversationId, null, $language, $e);
        }

        $output = $this->redaction->unmask(trim((string) $result['response']->content), $masked['values']);
        $verdict = DigitValidator::compare($text, $output);

        if (! $verdict['ok']) {
            $repair = $assembled['messages'];
            $repair[] = ['role' => 'system', 'content' => sprintf(
                'Your previous output altered numbers. Missing from your output: [%s]. Not in the source: [%s]. Produce the output again keeping EVERY number, amount, date and reference code exactly as in the source.',
                implode(', ', $verdict['missing']),
                implode(', ', $verdict['extra']),
            )];
            try {
                $result = $this->call($task, $repair, $stream?->restartAnd());
            } catch (AllProvidersExhausted|AiQuotaExhausted $e) {
                return $this->degraded($task, $agentId, $conversationId, null, $language, $e);
            }
            $output = $this->redaction->unmask(trim((string) $result['response']->content), $masked['values']);
            $verdict = DigitValidator::compare($text, $output);
        }

        $tokensIn = $result['response']->tokensIn;
        $tokensOut = $result['response']->tokensOut;

        if (! $verdict['ok']) {
            // §14: REJECTED — the agent gets the refusal, never the altered text.
            $id = $this->artifacts->mint($task, $kind, $agentId, $conversationId, null, $language, null, $result['provider'], $result['model'], $assembled['layer_hashes'], $tokensIn, $tokensOut, self::ms($started), 'guard_refused', $meta + ['digit_mismatch' => ['missing' => count($verdict['missing']), 'extra' => count($verdict['extra'])]], $request);

            return ['state' => 'refused', 'kind' => $kind, 'artifact_id' => $id, 'notices' => ['ai.copilot.notice.digit_mismatch']];
        }

        if ($output === '' || $this->refusals->isPolicyRefusal($result['response']) || $this->outputGuard->leaksSystemText($output)) {
            $id = $this->artifacts->mint($task, $kind, $agentId, $conversationId, null, $language, null, $result['provider'], $result['model'], $assembled['layer_hashes'], $tokensIn, $tokensOut, self::ms($started), 'invalid_action', $meta, $request);

            return ['state' => 'refused', 'kind' => $kind, 'artifact_id' => $id, 'notices' => ['ai.copilot.notice.unavailable']];
        }

        $id = $this->artifacts->mint($task, $kind, $agentId, $conversationId, null, $language, $output, $result['provider'], $result['model'], $assembled['layer_hashes'], $tokensIn, $tokensOut, self::ms($started), null, $meta, $request);

        return ['state' => 'artifact', 'kind' => $kind, 'artifact_id' => $id, 'text' => $output, 'notices' => []] + array_intersect_key($meta, ['source_language' => 1, 'target_language' => 1]);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{response: ProviderResponse, provider: string, model: string, latency_ms: int}
     */
    private function call(string $task, array $messages, ?CopilotStream $stream): array
    {
        if ($stream === null) {
            return $this->orchestrator->execute($task, $messages);
        }
        $result = $this->orchestrator->executeStreaming($task, $messages, static fn (string $delta) => $stream->delta($delta));
        $stream->close();

        return $result;
    }

    /**
     * FS-01's Copilot half: the degraded CARD (never a spinner, never a
     * fabricated draft) — a refused row records the category.
     *
     * @param  list<array{article_ref: string, excerpt: string}>  $kb
     * @return array<string, mixed>
     */
    private function degraded(string $task, string $agentId, string $conversationId, ?string $messageId, string $language, Throwable $cause, array $kb = []): array
    {
        $category = $cause instanceof AiQuotaExhausted ? 'quota_exhausted' : 'provider_error';
        $id = $this->artifacts->mint($task, $task, $agentId, $conversationId, $messageId, $language, null, 'none', 'none', [], 0, 0, 0, $category, ['degraded' => $category]);

        return ['state' => 'degraded', 'kind' => $task, 'artifact_id' => $id, 'reason' => $category, 'notices' => ['ai.copilot.notice.degraded'], 'kb' => $kb];
    }

    /**
     * @param  list<string>  $allowedRefs
     * @return array{text: string, citations: list<string>, confidence: float}|null
     */
    private static function parseDraft(string $content, array $allowedRefs): ?array
    {
        $decoded = json_decode(trim($content), true);
        if (! is_array($decoded) || ! is_string($decoded['text'] ?? null) || trim($decoded['text']) === '') {
            return null;
        }
        $citations = [];
        if (is_array($decoded['citations'] ?? null)) {
            foreach ($decoded['citations'] as $ref) {
                // AC-02's rule carried over: a citation is VALID only when it names a retrieved ref.
                if (is_string($ref) && in_array($ref, $allowedRefs, true)) {
                    $citations[] = $ref;
                }
            }
        }
        $confidence = is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.5;

        return ['text' => trim($decoded['text']), 'citations' => array_values(array_unique($citations)), 'confidence' => $confidence];
    }

    /** @return array{id: ?string, body: ?string, language: ?string} */
    private function latestCustomerMessage(string $conversationId): array
    {
        /** @var object{id: string, body: ?string, language: ?string}|null $row */
        $row = DB::table('messages')->where('conversation_id', $conversationId)->where('direction', 'in')->where('author_type', 'customer')
            ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'body', 'language']);

        return $row === null
            ? ['id' => null, 'body' => null, 'language' => null]
            : ['id' => $row->id, 'body' => $row->body, 'language' => in_array($row->language, ['en', 'am'], true) ? $row->language : null];
    }

    /** @return array<string, mixed> */
    private function latestClassification(string $conversationId): array
    {
        /** @var object{response_payload: ?string}|null $row */
        $row = DB::table('ai_decisions')->where('conversation_id', $conversationId)->where('task', 'classify_message')->orderByDesc('created_at')->first(['response_payload']);
        if ($row === null || ! is_string($row->response_payload)) {
            return [];
        }
        $decoded = json_decode($row->response_payload, true);
        $classification = is_array($decoded) ? ($decoded['classification'] ?? null) : null;

        return is_array($classification) ? self::stringKeyed($classification) : [];
    }

    /**
     * @return list<array{article_ref: string, excerpt: string}>
     */
    private function kbFor(Conversation $conversation, string $query, string $language): array
    {
        try {
            $retrieval = $this->retriever->retrieve($query, array_values(array_unique([$language, 'en'])), includeInternal: true, topK: 3);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $c): array => ['article_ref' => $c['article_ref'], 'excerpt' => mb_substr($c['content'], 0, 160)], $retrieval['chunks']);
    }

    private function customerLanguage(Conversation $conversation): string
    {
        $lang = DB::table('customers')->where('id', self::str($conversation->getAttribute('customer_id')))->value('language');

        return in_array($lang, ['en', 'am'], true) ? $lang : 'en';
    }

    /**
     * The staff-side window: customer + agent + ai turns, NEVER notes
     * (BR-06 — excluded by the query AND re-checked by ContextBuilder).
     *
     * @return list<array{role: string, content: string, kind?: string}>
     */
    private function window(string $conversationId, ?int $limit = null): array
    {
        $n = $limit ?? $this->settings->get('ai.chatbot.window_messages');
        $n = is_int($n) ? $n : 12;
        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->whereIn('author_type', ['customer', 'agent', 'ai'])
            ->where('kind', '!=', 'note')
            ->where('is_internal', false)
            ->orderByDesc('created_at')
            ->limit($n)
            ->get()
            ->reverse();

        $window = [];
        foreach ($rows as $row) {
            if (! is_string($row->body) || $row->body === '') {
                continue;
            }
            $window[] = ['role' => $row->author_type === 'customer' ? 'user' : 'assistant', 'content' => $row->body, 'kind' => self::str($row->kind)];
        }

        return $window;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $keyed[$key] = $value;
            }
        }

        return $keyed;
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }
}
