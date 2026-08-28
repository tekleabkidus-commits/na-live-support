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
 * Doc 10 FR-06 "Translate variant" / §14 / BR-02 (S10 step 8): ONE target variant gets its
 * working copy from the Doc 09 `translate` task (the P5 mapping) — source blocks in, the same
 * blocks out with translated text, written through the editor's OWN door (WorkingCopy: inert
 * content, the autosave stamp). The variant was born `translation_source=ai` and STAYS a draft:
 * this job never touches `status` (AC-09's static sweep reads this file). Numbers survive or
 * the draft is refused (Doc 09 §14's DigitValidator, the same rule the Copilot uses) — a
 * refused translation leaves the working copy EMPTY (absence, never a half-written draft).
 * Idempotent: a variant whose working copy is already filled, or that is not AI-sourced, is
 * left alone (a redelivery calls no model). `ai` family, CLAUDE.md §3: external AI from
 * queued jobs only.
 */
final class KbTranslateVariantJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public const TEMPLATE = <<<'TPL'
        Translate every "text" value in the JSON array below into %s (%s). Return ONLY a JSON array of objects with the SAME "id" values, in the same order, each with its translated "text". Preserve every number, currency amount, date, time, reference code and product name EXACTLY as written — never convert, round, localize or reorder digits. Keep the writing style (a help article for players); translate headings as headings.
        TPL;

    private const LANGUAGE_NAMES = ['en' => 'English', 'am' => 'Amharic'];

    public int $tries = 2;

    public function __construct(
        public readonly string $variantId,
        public readonly string $sourceVariantId,
        public readonly string $actorId,
    ) {
        $this->captureTenant();
        $this->onQueue('ai');
    }

    /**
     * The blocks that carry translatable text, by id (headings, paragraphs, list items, callouts…;
     * images/embeds are structure and ride through untouched).
     *
     * @return array<string, string> block id → text
     */
    public static function textBlocks(mixed $document): array
    {
        $out = [];
        if (! is_array($document) || ! is_array($document['blocks'] ?? null)) {
            return $out;
        }
        foreach ($document['blocks'] as $index => $block) {
            if (is_array($block) && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                $out[is_string($block['id'] ?? null) ? $block['id'] : 'b'.$index] = $block['text'];
            }
        }

        return $out;
    }

    public function handle(AiOrchestrator $orchestrator, LayeredAssembly $assembly, PiiRedaction $redaction, WorkingCopy $copies, ArticleWriter $articles, DecisionWriter $decisions, AuditLogger $audit): void
    {
        /** @var object{id: string, article_id: string, language: string, translation_source: string, working_copy: string|null}|null $target */
        $target = DB::table('kb_article_variants')->where('id', $this->variantId)->first(['id', 'article_id', 'language', 'translation_source', 'working_copy']);
        if ($target === null || $target->translation_source !== 'ai' || self::textBlocks(json_decode((string) $target->working_copy, true)) !== []) {
            return; // not ours, or already translated — C-09: a redelivery calls no model
        }
        /** @var object{id: string, language: string, title: string, working_copy: string|null}|null $source */
        $source = DB::table('kb_article_variants')->where('id', $this->sourceVariantId)->first(['id', 'language', 'title', 'working_copy']);
        $document = $source === null ? null : json_decode((string) $source->working_copy, true);
        $texts = self::textBlocks($document);
        if ($source === null || $texts === []) {
            return; // nothing to translate (the door refused this already; a later edit emptied it)
        }
        $items = [['id' => '__title', 'text' => $source->title]];
        foreach ($texts as $id => $text) {
            $items[] = ['id' => $id, 'text' => $text];
        }

        $masked = $redaction->maskWithValues(json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $template = sprintf(self::TEMPLATE, self::LANGUAGE_NAMES[$target->language] ?? $target->language, $target->language);
        $assembled = $assembly->assemble('translate', $template, [
            'language' => $target->language, 'prior_topics' => [], 'acquisition' => null,
            'window' => [['role' => 'user', 'content' => 'SOURCE ARTICLE ('.$source->language."):\n".$masked['text']]],
        ], [], keepReferences: true);
        $request = ['messages' => $assembled['messages'], 'masking_map' => $masked['map']];

        $started = microtime(true);
        $result = $orchestrator->execute('translate', $assembled['messages']); // exhaustion/quota throw = the retry path
        $output = $redaction->unmask(trim((string) $result['response']->content), $masked['values']);
        $translated = self::parse($output, array_keys($texts));
        $sourceJoined = implode("\n", array_values($texts));

        $action = 'variant_translated';
        $error = null;
        if ($translated === null) {
            [$action, $error] = ['translation_refused', 'invalid_action'];
        } elseif (! DigitValidator::compare($sourceJoined, implode("\n", array_values($translated['blocks'])))['ok']) {
            [$action, $error] = ['translation_refused', 'guard_refused']; // §14: numbers altered — the draft is never written
        }

        $decisionId = (string) new UuidV7;
        if ($translated !== null && $error === null) {
            /** @var array{blocks: list<array<string, mixed>>} $document */
            $blocks = [];
            foreach ($document['blocks'] as $index => $block) {
                $id = is_string($block['id'] ?? null) ? $block['id'] : 'b'.$index;
                if (isset($translated['blocks'][$id])) {
                    $block['text'] = $translated['blocks'][$id];
                }
                $blocks[] = $block;
            }
            DB::transaction(function () use ($copies, $articles, $audit, $target, $source, $blocks, $translated, $decisionId): void {
                $copies->save($target->id, ['blocks' => $blocks]);
                if ($translated['title'] !== null) {
                    $articles->updateMeta($target->id, ['title' => $translated['title']], $this->actorId);
                }
                $audit->record('kb.variant.ai_translated', 'kb_article_variant', $target->id, AuditActor::System, [
                    'article_id' => $target->article_id, 'source_variant_id' => $source->id, 'language' => $target->language, 'decision_id' => $decisionId, 'blocks' => count($blocks),
                ]);
            });
        }

        $decisions->record(new DecisionRecord(
            id: $decisionId, surface: 'copilot', task: 'translate',
            provider: $result['provider'], model: $result['model'], layerHashes: $assembled['layer_hashes'],
            language: $target->language, action: $action,
            tokensIn: $result['response']->tokensIn, tokensOut: $result['response']->tokensOut,
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            errorCategory: $error,
            requestPayload: $request,
            responsePayload: ['variant_id' => $target->id, 'source_variant_id' => $source->id, 'blocks' => $translated === null ? 0 : count($translated['blocks'])],
        ));
        $decisions->flush();
    }

    /**
     * @param  list<string>  $expectedIds
     * @return array{title: string|null, blocks: array<string, string>}|null null = unparseable or incomplete
     */
    private static function parse(string $output, array $expectedIds): ?array
    {
        $start = strpos($output, '[');
        $end = strrpos($output, ']');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            return null;
        }
        $title = null;
        $blocks = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['text'] ?? null) || trim($item['text']) === '') {
                continue;
            }
            if ($item['id'] === '__title') {
                $title = trim($item['text']);
            } else {
                $blocks[$item['id']] = $item['text'];
            }
        }
        foreach ($expectedIds as $id) {
            if (! isset($blocks[$id])) {
                return null; // a dropped block is not a translation
            }
        }

        return ['title' => $title, 'blocks' => $blocks];
    }
}
