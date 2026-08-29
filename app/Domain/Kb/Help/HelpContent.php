<?php

declare(strict_types=1);

namespace App\Domain\Kb\Help;

use App\Domain\Kb\Cache\KbCaches;
use App\Domain\Kb\Media\ArticleMedia;
use App\Domain\Kb\Translation\VariantForLanguage;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-18 / BR-01 / BR-05 / EC-05 (S10 step 11) — the customer-consumption read model:
 * PUBLIC articles, PUBLISHED variants, current-version content ONLY (BR-01 — a working copy
 * never reaches a customer surface). Language service is VariantForLanguage's one answer
 * (BR-05: exact-language `outdated` still serves; the fallback chain applies to `missing`).
 * Renders and the browse tree ride KbCaches (T-05/FS-03). Image blocks resolve to the
 * Doc 10 public media door's STABLE URLs (`/kb/media/{article}/{file}` — FR-05: public
 * long-lived URLs exist only behind the public+published check that door enforces); a
 * retention-deleted file renders `placeholder: true`, never a broken image (EC-05).
 * The widget rendering itself is Doc 13's ([D13]); Telegram's is Doc 05/12's — both
 * consume THIS contract.
 */
final class HelpContent
{
    public function __construct(
        private readonly KbCaches $caches,
        private readonly VariantForLanguage $language,
        private readonly ArticleMedia $media,
    ) {}

    /**
     * The browse tree: categories (per-language names) with their PUBLIC PUBLISHED
     * articles (title + slug per published variant). Cached whole; language picked at render.
     *
     * @return array{categories: list<array<string, mixed>>}
     */
    public function tree(): array
    {
        $cached = $this->caches->tree();
        if ($cached !== null) {
            /** @var array{categories: list<array<string, mixed>>} $cached */
            return $cached;
        }
        /** @var list<object{id: string, parent_id: string|null, name: string, slug: string, position: int}> $categories */
        $categories = DB::table('kb_categories')->whereNull('deleted_at')->orderBy('position')->orderBy('slug')->get(['id', 'parent_id', 'name', 'slug', 'position'])->all();
        /** @var list<object{id: string, article_id: string, category_id: string, language: string, title: string, slug: string}> $variants */
        $variants = DB::table('kb_article_variants as v')->join('kb_articles as a', 'a.id', '=', 'v.article_id')
            ->where('v.status', 'published')->where('a.audience', 'public')->whereNull('a.deleted_at')
            ->orderBy('v.title')->get(['v.id', 'v.article_id', 'a.category_id', 'v.language', 'v.title', 'v.slug'])->all();
        $byCategory = [];
        foreach ($variants as $v) {
            $byCategory[$v->category_id][] = ['article_id' => $v->article_id, 'variant_id' => $v->id, 'language' => $v->language, 'title' => $v->title, 'slug' => $v->slug];
        }
        $tree = ['categories' => array_map(fn (object $c): array => [
            'id' => $c->id, 'parent_id' => $c->parent_id, 'name' => json_decode($c->name, true), 'slug' => $c->slug,
            'articles' => $byCategory[$c->id] ?? [],
        ], $categories)];
        $this->caches->putTree($tree);

        return $tree;
    }

    /**
     * One article for a reader: BR-05 language service, current-version content, media
     * resolved (stable public URLs; EC-05 placeholders). Null = no public published
     * article behind this id (C-10: the caller 404s without explaining).
     *
     * @param  list<string>  $chain  most specific first (user → tenant default → en, C-08)
     * @return array<string, mixed>|null
     */
    public function article(string $articleId, array $chain): ?array
    {
        /** @var object{id: string, audience: string, deleted_at: string|null}|null $article */
        $article = DB::table('kb_articles')->where('id', $articleId)->first(['id', 'audience', 'deleted_at']);
        if ($article === null || $article->audience !== 'public' || $article->deleted_at !== null) {
            return null;
        }
        $served = $this->language->resolve($articleId, $chain);
        if ($served === null) {
            return null;
        }
        $cached = $this->caches->render($articleId, $served['language']);
        if ($cached !== null) {
            return $cached + ['served_language_matches' => $served['served_language_matches']];
        }
        /** @var object{id: string, title: string, summary: string|null, slug: string, current_version_id: string|null}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $served['variant_id'])->first(['id', 'title', 'summary', 'slug', 'current_version_id']);
        if ($variant === null || $variant->current_version_id === null) {
            return null;
        }
        $raw = DB::table('kb_article_versions')->where('id', $variant->current_version_id)->value('content');
        $decoded = json_decode(is_string($raw) ? $raw : '{}', true);
        /** @var array<string, mixed> $content */
        $content = is_array($decoded) ? $decoded : [];
        $payload = [
            'article_id' => $articleId, 'variant_id' => $variant->id, 'version_id' => $variant->current_version_id,
            'language' => $served['language'], 'translation_state' => $served['translation_state'],
            'title' => $variant->title, 'summary' => $variant->summary, 'slug' => $variant->slug,
            'blocks' => $this->media->resolveBlocks($articleId, array_values(is_array($content['blocks'] ?? null) ? $content['blocks'] : [])),
        ];
        $this->caches->putRender($articleId, $served['language'], $payload);

        return $payload + ['served_language_matches' => $served['served_language_matches']];
    }
}
