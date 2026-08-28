<?php

declare(strict_types=1);

namespace App\Domain\Kb\Translation;

use App\Domain\Kb\Articles\ArticleWriter;
use App\Jobs\Kb\KbTranslateVariantJob;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 §14 / FR-06 "Translate variant" / BR-02 (S10 step 8) — one-click "AI-translate all":
 * for every ACTIVE platform language (Doc 01 FR-10: `ref_languages`, not deprecated — tenants
 * select from reference data, never define it; no per-tenant active-language set exists in
 * Doc 01's schema, so the reference set IS the active set until Doc 16 says otherwise — filed
 * as interpretation A-47) that the article has NO variant in yet, this door creates a DRAFT
 * variant born `translation_source=ai` through ArticleWriter's own door and queues ONE
 * KbTranslateVariantJob per variant (AI runs from queued jobs, never inline — CLAUDE.md §3).
 * The jobs write working copies only; nothing here can publish (BR-02, AC-09). The source's
 * WORKING COPY is what gets translated (a seeded starter is a draft — it has no version).
 */
final class TranslateAll
{
    public function __construct(
        private readonly ArticleWriter $articles,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{source_variant_id: string, queued: list<array{variant_id: string, language: string}>} */
    public function run(string $articleId, string $actorId): array
    {
        /** @var object{id: string, source_language: string}|null $article */
        $article = DB::table('kb_articles')->where('id', $articleId)->whereNull('deleted_at')->first(['id', 'source_language']);
        if ($article === null) {
            throw new InvalidArgumentException('unknown article');
        }
        /** @var object{id: string, title: string, working_copy: string|null}|null $source */
        $source = DB::table('kb_article_variants')->where('article_id', $articleId)->where('language', $article->source_language)->first(['id', 'title', 'working_copy']);
        if ($source === null || $source->working_copy === null || KbTranslateVariantJob::textBlocks(json_decode($source->working_copy, true)) === []) {
            throw new SourceHasNoContent('the source-language variant has no content to translate');
        }

        /** @var list<string> $existing */
        $existing = DB::table('kb_article_variants')->where('article_id', $articleId)->pluck('language')->all();
        /** @var list<string> $targets */
        $targets = DB::table('ref_languages')->whereNull('deprecated_at')->whereNotIn('code', $existing)->orderBy('code')->pluck('code')->all();

        $queued = DB::transaction(function () use ($articleId, $actorId, $source, $targets): array {
            $queued = [];
            foreach ($targets as $language) {
                $variant = $this->articles->addVariant($articleId, $language, $source->title, $actorId, 'ai');
                $queued[] = ['variant_id' => $variant['variant_id'], 'language' => $language];
            }
            $this->audit->record('kb.article.translate_all_requested', 'kb_article', $articleId, AuditActor::Staff, [
                'source_variant_id' => $source->id, 'languages' => array_column($queued, 'language'), 'variant_ids' => array_column($queued, 'variant_id'),
            ], actorId: $actorId);

            return $queued;
        });

        // Dispatched AFTER the commit, explicitly — a worker must find the draft rows (C-06's posture, made local).
        foreach ($queued as $target) {
            KbTranslateVariantJob::dispatch($target['variant_id'], $source->id, $actorId);
        }

        return ['source_variant_id' => $source->id, 'queued' => $queued];
    }
}
