<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-14 / T-06 (S10 step 7b, packets P2/P3) — the link a citation renders as: the
 * article's CURRENT published version in the customer's language (the Doc 01 chain: asked
 * language → source language → any published), pinned by version id in the URL so the
 * forensics answer in one lookup (SS-04). A ref with no article row (Doc 09's golden
 * fixtures, pre-Doc 10 chunks) yields null and the caller keeps the bare ref — the
 * golden suite stays byte-identical (P2's control).
 */
final class CitationLinks
{
    /** @return array{url: string, version_id: string, title: string, language: string}|null */
    public function forArticle(string $articleRef, ?string $language = null): ?array
    {
        /** @var object{source_language: string}|null $article */
        $article = DB::table('kb_articles')->where('id', $articleRef)->whereNull('deleted_at')->first(['source_language']);
        if ($article === null) {
            return null;
        }
        $chain = array_values(array_unique(array_filter([$language, $article->source_language])));
        /** @var list<object{id: string, language: string, title: string, current_version_id: string|null}> $variants */
        $variants = DB::table('kb_article_variants')->where('article_id', $articleRef)->where('status', 'published')->whereNotNull('current_version_id')->get(['id', 'language', 'title', 'current_version_id'])->all();
        if ($variants === []) {
            return null;
        }
        usort($variants, static function (object $a, object $b) use ($chain): int {
            $ra = array_search($a->language, $chain, true);
            $rb = array_search($b->language, $chain, true);

            return ($ra === false ? 99 : $ra) <=> ($rb === false ? 99 : $rb);
        });
        $pick = $variants[0];

        return ['url' => CitationUrls::citation($articleRef, (string) $pick->current_version_id), 'version_id' => (string) $pick->current_version_id, 'title' => $pick->title, 'language' => $pick->language];
    }
}
