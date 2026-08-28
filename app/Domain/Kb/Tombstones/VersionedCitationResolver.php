<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-16 / T-06 / EC-06 / EC-07 / SS-04 (S10 step 7) — the resolver behind the
 * CitationResolver contract, now fed by the truth: kb_article_versions (immutable), the
 * variant's status/current version, the article's deleted_at, and kb_slug_history.
 * A once-cited URL NEVER 404s: Current (that version still serves), Retired (the
 * article was updated — a newer version serves, link to it — or unpublished/archived/
 * deleted — tombstone), Unknown (no such article/version — tombstone, no link). Legacy
 * citations minted before step 7 carry a bare article ref: resolve() answers those too.
 */
final class VersionedCitationResolver implements CitationResolver
{
    public function resolve(string $articleRef): CitationState
    {
        /** @var object{deleted_at: string|null}|null $article */
        $article = DB::table('kb_articles')->where('id', $articleRef)->first(['deleted_at']);
        if ($article === null) {
            // Pre-step-7 fixture chunks (article_ref with no article row) resolve by chunk state.
            return (new ChunkStateCitationResolver)->resolve($articleRef);
        }
        if ($article->deleted_at !== null) {
            return CitationState::Retired;
        }

        return DB::table('kb_article_variants')->where('article_id', $articleRef)->where('status', 'published')->exists() ? CitationState::Current : CitationState::Retired;
    }

    /** SS-04: the one lookup. */
    public function citation(string $articleId, string $versionId): CitationFacts
    {
        /** @var object{id: string, variant_id: string, language: string, title: string, slug: string, version: int|string, published_at: string}|null $version */
        $version = DB::table('kb_article_versions')->where('id', $versionId)->where('article_id', $articleId)->first(['id', 'variant_id', 'language', 'title', 'slug', 'version', DB::raw('published_at::text AS published_at')]);
        if ($version === null) {
            return new CitationFacts(CitationState::Unknown, $articleId, $versionId, null, null, null, 'kb.tombstone.unknown');
        }
        $shown = ['version' => (int) $version->version, 'title' => $version->title, 'language' => $version->language, 'published_at' => $version->published_at];
        /** @var object{deleted_at: string|null}|null $article */
        $article = DB::table('kb_articles')->where('id', $articleId)->first(['deleted_at']);
        /** @var object{status: string, current_version_id: string|null, slug: string, language: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $version->variant_id)->first(['status', 'current_version_id', 'slug', 'language']);
        if ($article === null || $article->deleted_at !== null || $variant === null || $variant->status !== 'published') {
            return new CitationFacts(CitationState::Retired, $articleId, $versionId, $shown, null, null, 'kb.tombstone.retired');
        }
        $currentUrl = CitationUrls::article($variant->language, $variant->slug);
        if ($variant->current_version_id === $versionId) {
            return new CitationFacts(CitationState::Current, $articleId, $versionId, $shown, $currentUrl, $versionId, 'kb.tombstone.current');
        }

        // Superseded: a newer version serves — "this article was updated", link to the current one.
        return new CitationFacts(CitationState::Retired, $articleId, $versionId, $shown, $currentUrl, $variant->current_version_id, 'kb.tombstone.retired');
    }
}
