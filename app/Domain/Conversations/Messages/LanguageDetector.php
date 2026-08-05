<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Messages;

/**
 * §14: "detected message language stored per message and exposed to Doc 09
 * translation features."
 *
 * SCRIPT DETECTION, NOT LANGUAGE IDENTIFICATION, and deliberately so. The two
 * launch languages are English and Amharic, which use disjoint scripts — Latin
 * and Ethiopic (U+1200–U+137F, plus the U+1380–U+139F supplement). Deciding
 * between them is therefore a character-range question with no ambiguity and no
 * dependency, where a statistical language-identification library would add a
 * composer dependency (ADR territory) and a model to be wrong in new ways.
 *
 * Its limits are stated rather than hidden:
 *  - it distinguishes SCRIPTS, so Tigrinya or Ge'ez (also Ethiopic) read as
 *    `am`, and any Latin-script language reads as `en`;
 *  - a message with no letters at all (digits, emoji, punctuation) yields null,
 *    because "unknown" is honest and a wrong tag is worse than no tag;
 *  - mixed content resolves by which script carries more letters.
 *
 * When Doc 09 brings a real translation provider, a better detector can replace
 * this one behind the same call — `language` is a nullable column and no
 * consumer may assume it is populated.
 */
final class LanguageDetector
{
    /** Ethiopic + Ethiopic Supplement — the Amharic script range. */
    private const ETHIOPIC = '/[\x{1200}-\x{137F}\x{1380}-\x{139F}]/u';

    private const LATIN = '/[A-Za-z]/u';

    public function detect(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $ethiopic = preg_match_all(self::ETHIOPIC, $body);
        $latin = preg_match_all(self::LATIN, $body);

        if ($ethiopic === 0 && $latin === 0) {
            return null; // no letters: digits, emoji, punctuation only
        }

        return $ethiopic >= $latin ? 'am' : 'en';
    }
}
