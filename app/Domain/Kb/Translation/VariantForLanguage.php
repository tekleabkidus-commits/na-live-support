<?php

declare(strict_types=1);

namespace App\Domain\Kb\Translation;

use Illuminate\Support\Facades\DB;

/**
 * Doc 10 BR-05 / §14 (S10 step 8) — which variant serves a reader's language: the EXACT
 * language wins even when `outdated` (stale-but-published beats a silent language
 * fallback); the Doc 01 chain applies ONLY to `missing` (no published variant in that
 * language). Every human consumption surface (manager read, widget Help, Telegram flow)
 * resolves through this one answer.
 */
final class VariantForLanguage
{
    /**
     * @param  list<string>  $chain  most specific first
     * @return array{variant_id: string, language: string, translation_state: string, served_language_matches: bool}|null
     */
    public function resolve(string $articleId, array $chain): ?array
    {
        /** @var list<object{id: string, language: string, translation_state: string}> $published */
        $published = DB::table('kb_article_variants')->where('article_id', $articleId)->where('status', 'published')->get(['id', 'language', 'translation_state'])->all();
        if ($published === []) {
            return null;
        }
        foreach ($chain as $index => $language) {
            foreach ($published as $variant) {
                if ($variant->language === $language) {
                    return ['variant_id' => $variant->id, 'language' => $variant->language, 'translation_state' => $variant->translation_state, 'served_language_matches' => $index === 0];
                }
            }
        }
        $first = $published[0];

        return ['variant_id' => $first->id, 'language' => $first->language, 'translation_state' => $first->translation_state, 'served_language_matches' => false];
    }
}
