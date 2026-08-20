<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Rg\RgClassifier;
use App\Domain\Conversations\Inbound\BatchTailHint;
use App\Domain\Conversations\Messages\LanguageDetector;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-05/FR-06/FR-07 + EC-01: the per-inbound-message classification stage —
 * a SUBSCRIBER to message.received (Doc 04's FR-10 step-6 fan-out contract:
 * persistence FIRST, AI after — NFR-02's ordering is the architecture, not
 * a promise), debounced to the BATCH TAIL (Doc 04's hint: ten rapid
 * messages, ONE classification covering the burst).
 *
 * Output: an ai_decisions row (the product's memory — Copilot context reads
 * it, FR-11's simulation replays it) carrying intent/confidence/rg_risk +
 * the FR-07 route in `action`. EXECUTION of the route lands with its owner:
 * rg_override at step 7, chatbot_turn/handoff at step 9 — the executor seam
 * below is consulted so those steps ATTACH rather than rewire.
 */
final class InboundClassification
{
    public const TEMPLATE = <<<'TPL'
        You are a message classifier for a betting-operator customer support platform. Classify the CUSTOMER BURST below. Return ONLY a JSON object, no prose, no fences:
        {"intent": one of ["deposits","withdrawals","bonuses","verification","technical","betting_rules","complaint","rg_risk","spam","other"], "sentiment": integer -2..2, "urgency": integer 0..3, "spam": number 0..1, "rg_risk": number 0..1}
        rg_risk scores gambling-harm signals (distress about losses, chasing losses, cannot stop, self-exclusion); service complaints are not risk.
        TPL;

    public function __construct(
        private readonly BatchTailHint $batchTail,
        private readonly LanguageDetector $language,
        private readonly AiOrchestrator $orchestrator,
        private readonly RgClassifier $rg,
        private readonly TurnDecision $decide,
        private readonly DecisionWriter $decisions,
        private readonly RouteExecutor $executor,
    ) {}

    /**
     * @return string|null the decision id, or null when this message is not
     *                     the batch tail (a newer arrival owns the burst)
     */
    public function classify(string $messageId): ?string
    {
        /** @var object{conversation_id: string, direction: string, quarantined: bool, language: ?string, body: ?string, author_id: ?string}|null $message */
        $message = DB::table('messages')->where('id', $messageId)->first();
        if ($message === null || $message->direction !== 'in' || $message->quarantined) {
            return null;
        }

        // EC-01: only the confirmed batch tail classifies; superseded
        // arrivals are silently covered by the newer tail's burst.
        $tail = $this->batchTail->tailFor($message->conversation_id);
        if ($tail !== null && $tail !== $messageId) {
            return null;
        }

        /** @var object{channel: string, channel_ref_id: ?string}|null $conversation */
        $conversation = DB::table('conversations')->where('id', $message->conversation_id)->first();
        if ($conversation === null) {
            return null;
        }

        $burst = $this->burstText($message->conversation_id);
        $language = $message->language !== null && $message->language !== ''
            ? $message->language
            : ($this->language->detect($message->body) ?? 'en');

        $started = microtime(true);
        $result = $this->orchestrator->execute('classify_message', [
            ['role' => 'system', 'content' => self::TEMPLATE],
            ['role' => 'user', 'content' => $burst],
        ]);

        $decoded = json_decode((string) $result['response']->content, true);
        $classification = $this->normalize(is_array($decoded) ? $decoded : []);

        // T-06's two-stage RG confirmation (precision-first, NFR-07).
        $rg = $this->rg->confirm($burst, $classification['rg_risk']);
        $classification['rg_risk'] = $rg['rg_risk'];

        $route = $this->decide->decide(
            $message->conversation_id,
            $conversation->channel,
            $conversation->channel_ref_id,
            $classification['rg_risk'],
        );

        $decisionId = (string) new UuidV7;
        $this->decisions->record(new DecisionRecord(
            id: $decisionId,
            surface: 'chatbot',
            task: 'classify_message',
            provider: $result['provider'],
            model: $result['model'],
            layerHashes: ['immutable' => \App\Domain\Ai\Prompt\ImmutableLayer::hash(), 'task_template' => hash('sha256', self::TEMPLATE)],
            conversationId: $message->conversation_id,
            customerId: $message->author_id,
            messageId: $messageId,
            endpointRef: $conversation->channel_ref_id,
            language: $language,
            intent: $classification['intent'],
            confidence: $classification['confidence'],
            action: $route,
            rgRisk: $classification['rg_risk'],
            tokensIn: $result['response']->tokensIn + $rg['tokens_in'],
            tokensOut: $result['response']->tokensOut + $rg['tokens_out'],
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            requestPayload: ['burst' => $burst],
            responsePayload: ['classification' => $classification],
        ));
        $this->decisions->flush();

        // The route EXECUTES through the seam its owning step implements
        // (step 7: rg_override; step 9: chatbot_turn + handoff_path;
        // copilot_context needs no act — the decision row IS the delivery).
        $this->executor->execute($route, $decisionId, $message->conversation_id, $messageId, $language, $classification);

        return $decisionId;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array{intent: string, confidence: float, sentiment: int, urgency: int, spam: float, rg_risk: float}
     */
    private function normalize(array $decoded): array
    {
        $intents = ['deposits', 'withdrawals', 'bonuses', 'verification', 'technical', 'betting_rules', 'complaint', 'rg_risk', 'spam', 'other'];
        $intent = is_string($decoded['intent'] ?? null) && in_array($decoded['intent'], $intents, true) ? $decoded['intent'] : 'other';

        $number = static fn (mixed $value, float $default = 0.0): float => is_numeric($value) ? (float) $value : $default;

        return [
            'intent' => $intent,
            'confidence' => max(0.0, min(1.0, $number($decoded['confidence'] ?? 0.7, 0.7))),
            'sentiment' => (int) max(-2, min(2, $number($decoded['sentiment'] ?? 0))),
            'urgency' => (int) max(0, min(3, $number($decoded['urgency'] ?? 0))),
            'spam' => max(0.0, min(1.0, $number($decoded['spam'] ?? 0))),
            'rg_risk' => max(0.0, min(1.0, $number($decoded['rg_risk'] ?? 0))),
        ];
    }

    /** EC-01: the burst = recent inbound texts, oldest first, one block. */
    private function burstText(string $conversationId): string
    {
        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'in')
            ->where('quarantined', false)
            ->whereRaw("created_at > clock_timestamp() - interval '2 minutes'")
            ->orderBy('created_at')
            ->limit(10)
            ->pluck('body');

        $texts = [];
        foreach ($rows as $body) {
            if (is_string($body) && trim($body) !== '') {
                $texts[] = $body;
            }
        }

        return implode("\n", $texts);
    }
}
