<?php

declare(strict_types=1);

namespace App\Jobs\Kb;

use App\Domain\Ai\Copilot\DigitValidator;
use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Prompt\PiiRedaction;
use App\Domain\Kb\Articles\ArticleWriter;
use App\Domain\Kb\Editor\WorkingCopy;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 FR-06 / BR-02 / AC-09 (S10 step 9): ONE queued writer for the four authoring tools —
 * draft-from-source (kb_draft_from_source), simplify (improve_text), FAQ variants
 * (kb_faq_variants), draft-from-conversations (kb_draft_from_conversations; the message IDS ride
 * in the payload, the customer text is read here, server-side, and leaves the process only
 * through the assembled request — under the egress guard by mechanism). Output is a block
 * document; every block the model produced carries `ai: true` (the editor's AI accent, cleared
 * when a human edits — [B9]) and lands through WorkingCopy::save (inert) + updateMeta. Nothing in
 * this file names `status` or a transition. Invented digits (DigitValidator "extra") refuse the
 * draft; simplify also refuses dropped digits. A refusal writes NOTHING. Redelivery: an accented
 * copy already present = no model call (C-09).
 */
final class KbAuthoringJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public const TEMPLATES = [
        'kb_draft_from_source' => <<<'TPL'
            Turn the SOURCE TEXT below into a help-centre article for betting players. Return ONLY a JSON object: {"title": string, "summary": string (one sentence), "blocks": [{"type": "heading"|"paragraph"|"list", "text": string, "items": [string] (lists only)}]}. Keep every number, amount, date, percentage and reference EXACTLY as in the source — never invent, convert or round. Plain language, short sections.
            TPL,
        'improve_text' => <<<'TPL'
            Rewrite every "text" value in the JSON array below at a simpler reading level (short sentences, common words), keeping the meaning and EVERY number, amount, date and reference exactly as written. Return ONLY a JSON array of objects with the SAME "id" values, in the same order, each with its rewritten "text".
            TPL,
        'kb_faq_variants' => <<<'TPL'
            From the ARTICLE below, write 3 to 6 alternative ways a player might ASK the question this article answers (different wording, same meaning, the article's language). Return ONLY a JSON array of strings. Do not add numbers that are not in the article.
            TPL,
        'kb_draft_from_conversations' => <<<'TPL'
            The CUSTOMER QUESTIONS below were asked this week and the support bot could not answer them. Draft ONE help-centre article that answers them: use the questions (paraphrased, generic — no names, no amounts, no account details) as "heading" blocks, each followed by a short "paragraph" placeholder the operator will complete. Return ONLY a JSON object: {"title": string, "summary": string (one sentence), "blocks": [{"type": "heading"|"paragraph"|"list", "text": string, "items": [string] (lists only)}]}. Never invent policy facts or numbers.
            TPL,
    ];

    public int $tries = 2;

    /** @param  array<string, mixed>  $params  text (from-source) or message_ids + intent (from-conversations); identifiers otherwise */
    public function __construct(
        public readonly string $task,
        public readonly string $variantId,
        public readonly string $actorId,
        public readonly array $params = [],
    ) {
        $this->captureTenant();
        $this->onQueue('ai');
    }

    /** @return array<string, string> block id → text (the same rule as the translate job) */
    public static function textBlocks(mixed $document): array
    {
        return KbTranslateVariantJob::textBlocks($document);
    }

    public function handle(AiOrchestrator $orchestrator, LayeredAssembly $assembly, PiiRedaction $redaction, WorkingCopy $copies, ArticleWriter $articles, DecisionWriter $decisions, AuditLogger $audit): void
    {
        /** @var object{id: string, article_id: string, language: string, title: string, working_copy: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $this->variantId)->first(['id', 'article_id', 'language', 'title', 'working_copy']);
        if ($variant === null || ! isset(self::TEMPLATES[$this->task])) {
            return;
        }
        /** @var array{blocks?: list<array<string, mixed>>} $document */
        $document = json_decode($variant->working_copy, true) ?: [];
        $blocks = array_values(array_filter($document['blocks'] ?? [], 'is_array'));
        if ($this->alreadyDone($blocks)) {
            return; // C-09: the accented output is already there — no second model call
        }

        [$userText, $sourceForDigits] = $this->input($variant->title, $blocks);
        if ($userText === null) {
            return; // nothing to work on (the door refused this already; a later edit emptied it)
        }
        $masked = $redaction->maskWithValues($userText);
        $assembled = $assembly->assemble($this->task, self::TEMPLATES[$this->task], [
            'language' => $variant->language, 'prior_topics' => [], 'acquisition' => null,
            'window' => [['role' => 'user', 'content' => $masked['text']]],
        ], [], keepReferences: true);
        $request = ['messages' => $assembled['messages'], 'masking_map' => $masked['map']];

        $started = microtime(true);
        $result = $orchestrator->execute($this->task, $assembled['messages']);
        $output = $redaction->unmask(trim((string) $result['response']->content), $masked['values']);

        $produced = $this->produce($output, $blocks);
        $action = 'draft_written';
        $error = null;
        if ($produced === null) {
            [$action, $error] = ['draft_refused', 'invalid_action'];
        } else {
            $verdict = DigitValidator::compare($sourceForDigits, $produced['digits_text']);
            $strict = $this->task === 'improve_text';
            if ($verdict['extra'] !== [] || ($strict && ! $verdict['ok'])) {
                [$action, $error] = ['draft_refused', 'guard_refused'];
            }
        }

        $decisionId = (string) new UuidV7;
        if ($produced !== null && $error === null) {
            DB::transaction(function () use ($copies, $articles, $audit, $variant, $produced, $decisionId): void {
                $copies->save($variant->id, ['blocks' => $produced['blocks']]);
                if ($produced['meta'] !== []) {
                    $articles->updateMeta($variant->id, $produced['meta'], $this->actorId);
                }
                $audit->record('kb.variant.ai_drafted', 'kb_article_variant', $variant->id, AuditActor::System, [
                    'article_id' => $variant->article_id, 'task' => $this->task, 'decision_id' => $decisionId, 'blocks' => count($produced['blocks']),
                ]);
            });
        }

        $decisions->record(new DecisionRecord(
            id: $decisionId, surface: 'copilot', task: $this->task,
            provider: $result['provider'], model: $result['model'], layerHashes: $assembled['layer_hashes'],
            language: $variant->language, action: $action,
            tokensIn: $result['response']->tokensIn, tokensOut: $result['response']->tokensOut,
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            errorCategory: $error,
            requestPayload: $request,
            responsePayload: ['variant_id' => $variant->id, 'blocks' => $produced === null ? 0 : count($produced['blocks'])] + (isset($this->params['message_ids']) ? ['message_ids' => $this->params['message_ids']] : []),
        ));
        $decisions->flush();
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private function alreadyDone(array $blocks): bool
    {
        $accented = array_filter($blocks, static fn (array $b): bool => ($b['ai'] ?? false) === true);
        if ($this->task === 'kb_faq_variants') {
            return array_filter($accented, static fn (array $b): bool => ($b['type'] ?? null) === 'callout' && ($b['label_key'] ?? null) === 'kb.blocks.also_asked') !== [];
        }
        if ($this->task === 'improve_text') {
            return $accented !== [] && count($accented) === count($blocks);
        }

        return $accented !== [];
    }

    /**
     * The user-role content and the digit source, per task.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: string|null, 1: string}
     */
    private function input(string $title, array $blocks): array
    {
        $texts = self::textBlocks(['blocks' => $blocks]);
        switch ($this->task) {
            case 'kb_draft_from_source':
                $text = is_string($this->params['text'] ?? null) ? $this->params['text'] : '';

                return $text === '' ? [null, ''] : ["SOURCE TEXT:\n".$text, $text];
            case 'improve_text':
                if ($texts === []) {
                    return [null, ''];
                }
                $items = [];
                foreach ($texts as $id => $t) {
                    $items[] = ['id' => $id, 'text' => $t];
                }

                return [json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), implode("\n", $texts)];
            case 'kb_faq_variants':
                if ($texts === []) {
                    return [null, ''];
                }
                $article = 'ARTICLE TITLE: '.$title."\n".implode("\n", $texts);

                return [$article, $article];
            default: // kb_draft_from_conversations — IDS in, text read HERE
                /** @var list<string> $ids */
                $ids = array_values(array_filter((array) ($this->params['message_ids'] ?? []), 'is_string'));
                if ($ids === []) {
                    return [null, ''];
                }
                /** @var list<string> $bodies */
                $bodies = DB::table('messages')->whereIn('id', $ids)->where('author_type', 'customer')->orderBy('created_at')->limit(50)->pluck('body')->filter(fn ($b) => is_string($b) && trim($b) !== '')->values()->all();
                if ($bodies === []) {
                    return [null, ''];
                }
                $joined = "CUSTOMER QUESTIONS:\n- ".implode("\n- ", $bodies);

                return [$joined, $joined];
        }
    }

    /**
     * Parse + shape the model output into the document to save.
     *
     * @param  list<array<string, mixed>>  $blocks  the current working copy
     * @return array{blocks: list<array<string, mixed>>, meta: array<string, string>, digits_text: string}|null
     */
    private function produce(string $output, array $blocks): ?array
    {
        $decoded = self::json($output);
        if ($decoded === null) {
            return null;
        }
        if ($this->task === 'improve_text') {
            $byId = [];
            foreach ($decoded as $item) {
                if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['text'] ?? null) && trim($item['text']) !== '') {
                    $byId[$item['id']] = $item['text'];
                }
            }
            $out = [];
            $changed = 0;
            foreach ($blocks as $index => $block) {
                $id = is_string($block['id'] ?? null) ? $block['id'] : 'b'.$index;
                if (isset($byId[$id])) {
                    $block['text'] = $byId[$id];
                    $block['ai'] = true;
                    $changed++;
                }
                $out[] = $block;
            }
            if ($changed !== count(self::textBlocks(['blocks' => $blocks]))) {
                return null; // a dropped block is not a rewrite
            }

            return ['blocks' => $out, 'meta' => [], 'digits_text' => implode("\n", $byId)];
        }
        if ($this->task === 'kb_faq_variants') {
            $items = array_values(array_filter($decoded, static fn ($q): bool => is_string($q) && trim($q) !== ''));
            if ($items === []) {
                return null;
            }
            $blocks[] = ['id' => 'ai-'.substr((string) new UuidV7, -8), 'type' => 'callout', 'label_key' => 'kb.blocks.also_asked', 'text' => '', 'items' => $items, 'ai' => true];

            return ['blocks' => $blocks, 'meta' => [], 'digits_text' => implode("\n", $items)];
        }
        // the two drafts: {title, summary, blocks}
        $title = is_string($decoded['title'] ?? null) ? trim($decoded['title']) : '';
        $summary = is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '';
        $out = [];
        $digits = [$title, $summary];
        foreach (is_array($decoded['blocks'] ?? null) ? $decoded['blocks'] : [] as $i => $b) {
            if (! is_array($b) || ! is_string($b['text'] ?? null)) {
                continue;
            }
            $type = in_array($b['type'] ?? null, ['heading', 'paragraph', 'list'], true) ? $b['type'] : 'paragraph';
            $block = ['id' => 'ai-'.($i + 1), 'type' => $type, 'text' => $b['text'], 'ai' => true];
            $digits[] = $b['text'];
            if ($type === 'list') {
                $block['items'] = array_values(array_filter((array) ($b['items'] ?? []), 'is_string'));
                $digits = array_merge($digits, $block['items']);
            }
            $out[] = $block;
        }
        if ($out === [] || $title === '') {
            return null;
        }
        $meta = ['title' => $title];
        if ($summary !== '') {
            $meta['summary'] = $summary;
        }

        return ['blocks' => $out, 'meta' => $meta, 'digits_text' => implode("\n", $digits)];
    }

    /** @return array<mixed>|null */
    private static function json(string $output): ?array
    {
        $open = $output[strcspn($output, '[{')] ?? '';
        $close = $open === '[' ? ']' : '}';
        $start = strpos($output, $open === '' ? '{' : $open);
        $end = strrpos($output, $close);
        if ($open === '' || $start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
