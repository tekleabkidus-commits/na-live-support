<?php

declare(strict_types=1);

namespace App\Domain\Kb\Articles;

use App\Domain\Kb\Categories\CategoryTree;
use App\Domain\Kb\Slug\Slugger;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 FR-02 — one logical article = N language variants sharing an article id.
 * AUDIENCE is ARTICLE-level (BR-03: one decision per article; no per-variant column
 * exists — physics), STATUS is VARIANT-level (Published-in-am / Draft-in-en is normal).
 * The source language is per article (§14: an Amharic-first article is normal).
 * This writer creates articles, adds variants (born draft, translation_state
 * missing), edits metadata, and moves articles between categories (EC-02: into a
 * non-ai_usable category = chunks retired on the unpublish path). It NEVER writes
 * status, audience or versions — those are the workflow/visibility doors (step 4).
 */
final class ArticleWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Slugger $slugger,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * @param  list<string>  $tags
     * @return array{article_id: string, variant_id: string, slug: string}
     */
    public function create(string $categoryId, string $audience, string $sourceLanguage, string $title, string $actorId, array $tags = [], bool $seeded = false, ?string $seededFingerprint = null): array
    {
        if (! in_array($audience, ['public', 'internal'], true)) {
            throw new InvalidArgumentException('audience is public or internal (FR-02/BR-03)');
        }
        $category = DB::table('kb_categories')->where('id', $categoryId)->whereNull('deleted_at')->first(['id']);
        if ($category === null) {
            throw new InvalidArgumentException('unknown category');
        }
        $articleId = (string) new UuidV7;

        return DB::transaction(function () use ($articleId, $categoryId, $audience, $sourceLanguage, $title, $actorId, $tags, $seeded, $seededFingerprint): array {
            DB::table('kb_articles')->insert([
                'id' => $articleId, 'tenant_id' => $this->context->tenantId(), 'category_id' => $categoryId, 'audience' => $audience,
                'source_language' => $sourceLanguage, 'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                'seeded' => $seeded, 'seeded_fingerprint' => $seededFingerprint, 'created_by' => $actorId,
            ]);
            $variant = $this->insertVariant($articleId, $sourceLanguage, $title);
            $this->audit->record('kb.article.created', 'kb_article', $articleId, AuditActor::Staff, ['audience' => $audience, 'source_language' => $sourceLanguage, 'seeded' => $seeded], actorId: $actorId);

            return ['article_id' => $articleId] + $variant;
        });
    }

    /** @return array{variant_id: string, slug: string} */
    public function addVariant(string $articleId, string $language, string $title, string $actorId, string $translationSource = 'human'): array
    {
        $article = DB::table('kb_articles')->where('id', $articleId)->whereNull('deleted_at')->first(['id']);
        if ($article === null) {
            throw new InvalidArgumentException('unknown article');
        }

        return DB::transaction(function () use ($articleId, $language, $title, $actorId, $translationSource): array {
            $variant = $this->insertVariant($articleId, $language, $title, $translationSource);
            $this->audit->record('kb.variant.created', 'kb_article_variant', $variant['variant_id'], AuditActor::Staff, ['article_id' => $articleId, 'language' => $language, 'translation_source' => $translationSource], actorId: $actorId);

            return $variant;
        });
    }

    /**
     * Title/summary/tags/related edits on a variant + its article (working copy is the editor's — WorkingCopy).
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateMeta(string $variantId, array $changes, string $actorId): void
    {
        /** @var object{id: string, article_id: string, language: string, slug: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'language', 'slug']);
        if ($variant === null) {
            throw new InvalidArgumentException('unknown variant');
        }
        DB::transaction(function () use ($variant, $changes, $actorId): void {
            $variantRow = array_intersect_key($changes, array_flip(['title', 'summary']));
            $title = $changes['title'] ?? null;
            $slug = $changes['slug'] ?? null;
            if (is_string($title) && ! is_string($slug)) {
                $variantRow['slug'] = $this->uniqueSlug($this->slugger->slug($title, $variant->language), $variant->language, $variant->id);
            } elseif (is_string($slug)) {
                $variantRow['slug'] = $this->uniqueSlug($slug, $variant->language, $variant->id);
            }
            if ($variantRow !== []) {
                // T-06/EC-06: a slug change keeps the OLD slug alive in kb_slug_history (append-only) — old URLs redirect.
                if (isset($variantRow['slug']) && $variantRow['slug'] !== $variant->slug) {
                    DB::table('kb_slug_history')->insert(['id' => (string) new UuidV7, 'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variant->id, 'language' => $variant->language, 'slug' => $variant->slug]);
                }
                DB::table('kb_article_variants')->where('id', $variant->id)->update($variantRow + ['updated_at' => DB::raw('clock_timestamp()')]);
            }
            $articleRow = [];
            foreach (['tags', 'related_article_ids'] as $json) {
                if (array_key_exists($json, $changes)) {
                    $articleRow[$json] = json_encode(array_values((array) $changes[$json]), JSON_THROW_ON_ERROR);
                }
            }
            if ($articleRow !== []) {
                DB::table('kb_articles')->where('id', $variant->article_id)->update($articleRow + ['updated_at' => DB::raw('clock_timestamp()')]);
            }
            $this->audit->record('kb.variant.updated', 'kb_article_variant', $variant->id, AuditActor::Staff, ['fields' => array_keys($variantRow + $articleRow)], actorId: $actorId);
        });
    }

    /** EC-02: a move into a non-ai_usable category retires the chunks on the unpublish path; back into one marks them pending. */
    public function move(string $articleId, string $categoryId, string $actorId): void
    {
        DB::transaction(function () use ($articleId, $categoryId, $actorId): void {
            /** @var object{category_id: string}|null $article */
            $article = DB::table('kb_articles')->where('id', $articleId)->whereNull('deleted_at')->first(['category_id']);
            /** @var object{ai_usable: bool}|null $target */
            $target = DB::table('kb_categories')->where('id', $categoryId)->whereNull('deleted_at')->first(['ai_usable']);
            if ($article === null || $target === null) {
                throw new InvalidArgumentException('unknown article or category');
            }
            $sourceUsable = (bool) DB::table('kb_categories')->where('id', $article->category_id)->value('ai_usable');
            DB::table('kb_articles')->where('id', $articleId)->update(['category_id' => $categoryId, 'updated_at' => DB::raw('clock_timestamp()')]);
            if ((bool) $target->ai_usable !== $sourceUsable) {
                $this->categories->applyAiUsable($articleId, (bool) $target->ai_usable);
            }
            $this->audit->record('kb.article.moved', 'kb_article', $articleId, AuditActor::Staff, ['old' => ['category_id' => $article->category_id], 'new' => ['category_id' => $categoryId], 'ai_usable_changed' => (bool) $target->ai_usable !== $sourceUsable], actorId: $actorId);
        });
    }

    /** @return array{variant_id: string, slug: string} */
    private function insertVariant(string $articleId, string $language, string $title, string $translationSource = 'human'): array
    {
        $id = (string) new UuidV7;
        $slug = $this->uniqueSlug($this->slugger->slug($title, $language), $language, null);
        try {
            DB::table('kb_article_variants')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(), 'article_id' => $articleId, 'language' => $language,
                'status' => 'draft', 'translation_state' => 'missing', 'translation_source' => $translationSource,
                'title' => $title, 'slug' => $slug, 'summary' => '', 'working_copy' => '{}', 'index_status' => 'none', 'retrieval_generation' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException(sprintf('the article already has a %s variant (FR-02: one variant per language)', $language));
        }

        return ['variant_id' => $id, 'slug' => $slug];
    }

    /** EC-06: a taken slug gets an auto-suffix (the caller surfaces the warning); slug history is step 7's. */
    private function uniqueSlug(string $base, string $language, ?string $selfId): string
    {
        $candidate = $base;
        for ($n = 2; $n < 1000; $n++) {
            $taken = DB::table('kb_article_variants')->where('language', $language)->where('slug', $candidate)
                ->when($selfId !== null, fn ($q) => $q->where('id', '<>', $selfId))->exists()
                || DB::table('kb_slug_history')->where('language', $language)->where('slug', $candidate)->when($selfId !== null, fn ($q) => $q->where('variant_id', '<>', $selfId))->exists();
            if (! $taken) {
                return $candidate;
            }
            $candidate = $base.'-'.$n;
        }
        throw new InvalidArgumentException('no free slug');
    }
}
