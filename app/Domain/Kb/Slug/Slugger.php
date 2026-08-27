<?php

declare(strict_types=1);

namespace App\Domain\Kb\Slug;

/**
 * Doc 10 §14 — per-language slugs with a TRANSLITERATION HELPER for Amharic:
 * Ethiopic syllables (U+1200–U+137F) are laid out as consonant blocks of eight
 * orders (ä u i a e ə o + a labialized eighth), so a base-consonant table plus
 * an order table transliterates every fidel deterministically — an
 * Amharic-first article gets a readable Latin slug without a human step.
 * Deterministic: same title → same slug (EC-06's collision handling is the
 * caller's — auto-suffix).
 */
final class Slugger
{
    /** Latin base per Ethiopic consonant block (index = (cp - 0x1200) >> 3). */
    private const CONSONANTS = [
        'h', 'l', 'h', 'm', 's', 'r', 's', 'sh',      // 0x1200 ሀ ለ ሐ መ ሠ ረ ሰ ሸ
        'q', 'q', 'qw', 'q', 'b', 'v', 't', 'ch',     // 0x1240 ቀ ቐ ቘ ቚ በ ቨ ተ ቸ
        'h', 'hw', 'n', 'ny', '', 'k', 'kw', 'x',     // 0x1280 ኀ ኈ ነ ኘ አ ከ ኰ ኸ
        'xw', 'w', '', 'z', 'zh', 'y', 'd', 'dd',     // 0x12C0 ዀ ወ ዐ ዘ ዠ የ ደ ዸ
        'j', 'g', 'gw', 'gg', 'th', 'ch', 'ph', 'ts', // 0x1300 ጀ ገ ጐ ጘ ጠ ጨ ጰ ጸ
        'ts', 'f', 'p', 'rya', 'mya', 'fya',          // 0x1340 ፀ ፈ ፐ ፘ ፚ …
    ];

    private const ORDERS = ['e', 'u', 'i', 'a', 'ie', '', 'o', 'wa'];

    public function slug(string $title, string $language): string
    {
        $text = mb_strtolower(trim($title));
        if ($language === 'am' || preg_match('/[\x{1200}-\x{137F}]/u', $text) === 1) {
            $text = $this->transliterateEthiopic($text);
        }
        $ascii = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text) ?? '';
        $ascii = trim((string) $ascii, '-');

        return $ascii === '' ? 'article' : mb_substr($ascii, 0, 80);
    }

    public function transliterateEthiopic(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $cp = mb_ord($char);
            if ($cp === 0x1361 || $cp === 0x1362) { // ፡ wordspace, ። full stop
                $out .= ' ';

                continue;
            }
            if ($cp < 0x1200 || $cp > 0x137F) {
                $out .= $char;

                continue;
            }
            $block = ($cp - 0x1200) >> 3;
            $order = ($cp - 0x1200) & 7;
            $base = self::CONSONANTS[$block] ?? '';
            $vowel = self::ORDERS[$order];
            // A bare-vowel block (glottal/pharyngeal) renders the vowel alone; the
            // sixth order (ə) is written bare after a consonant.
            $out .= $base === '' ? ($vowel === '' ? 'i' : $vowel) : $base.($vowel === '' && $order === 5 ? '' : $vowel);
        }

        return $out;
    }
}
