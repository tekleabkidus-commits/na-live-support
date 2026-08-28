<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

use Illuminate\Support\Facades\DB;

/**
 * Doc 10 T-06 / EC-06 / FR-16 — article URLs by (language, slug): the current slug serves
 * the article; a slug from kb_slug_history REDIRECTS to the current one (every old URL
 * keeps working); a slug whose variant is no longer published answers a tombstone —
 * never a 404 for a once-used URL.
 *
 * @phpstan-type Resolution array{state: string, article_id: string|null, variant_id: string|null, redirect_to: string|null, tombstone_key: string|null}
 */
final class SlugResolver
{
    /** @return array{state: string, article_id: string|null, variant_id: string|null, redirect_to: string|null, tombstone_key: string|null} */
    public function resolve(string $language, string $slug): array
    {
        /** @var object{id: string, article_id: string, status: string, slug: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('language', $language)->where('slug', $slug)->first(['id', 'article_id', 'status', 'slug']);
        if ($variant === null) {
            /** @var object{variant_id: string, article_id: string}|null $history */
            $history = DB::table('kb_slug_history')->where('language', $language)->where('slug', $slug)->orderByDesc('id')->first(['variant_id', 'article_id']);
            if ($history === null) {
                return ['state' => 'unknown', 'article_id' => null, 'variant_id' => null, 'redirect_to' => null, 'tombstone_key' => 'kb.tombstone.unknown'];
            }
            /** @var object{id: string, article_id: string, status: string, slug: string}|null $current */
            $current = DB::table('kb_article_variants')->where('id', $history->variant_id)->first(['id', 'article_id', 'status', 'slug']);
            if ($current === null) {
                return ['state' => 'retired', 'article_id' => $history->article_id, 'variant_id' => $history->variant_id, 'redirect_to' => null, 'tombstone_key' => 'kb.tombstone.retired'];
            }
            $variant = $current;
            if ($variant->status === 'published') {
                return ['state' => 'redirect', 'article_id' => $variant->article_id, 'variant_id' => $variant->id, 'redirect_to' => CitationUrls::article($language, $variant->slug), 'tombstone_key' => null];
            }
        }
        $deleted = DB::table('kb_articles')->where('id', $variant->article_id)->value('deleted_at') !== null;
        if ($variant->status !== 'published' || $deleted) {
            return ['state' => 'retired', 'article_id' => $variant->article_id, 'variant_id' => $variant->id, 'redirect_to' => null, 'tombstone_key' => 'kb.tombstone.retired'];
        }

        return ['state' => 'current', 'article_id' => $variant->article_id, 'variant_id' => $variant->id, 'redirect_to' => null, 'tombstone_key' => null];
    }
}
