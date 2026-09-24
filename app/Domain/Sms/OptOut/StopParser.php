<?php

declare(strict_types=1);

namespace App\Domain\Sms\OptOut;

use App\Support\Settings\SettingsRepository;

/**
 * FR-15's parser — the smallest honest thing: the inbound is trimmed of
 * ASCII whitespace ONLY (space, tab, CR, LF) and then BYTE-COMPARED against
 * each configured stop word per language (`sms.stop_words`). No case folding,
 * no Unicode normalization, no transliteration — the ተወው lesson: the word's
 * bytes are the word (StopWordByteExactTest pins them from the document
 * itself), and altering an Ethiopic string to "help" matching is how a real
 * STOP gets missed. A near-miss is NOT an opt-out; it stays a counted drop
 * at the MO door.
 */
final class StopParser
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{language: string, word: string}|null */
    public function match(string $inbound): ?array
    {
        $trimmed = trim($inbound, " \t\r\n");
        if ($trimmed === '') {
            return null;
        }

        foreach ($this->stopWords() as $language => $words) {
            foreach ($words as $word) {
                if ($trimmed === $word) {
                    return ['language' => $language, 'word' => $word];
                }
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    public function stopWords(): array
    {
        $configured = $this->settings->get('sms.stop_words');
        if (! is_array($configured)) {
            return [];
        }
        $out = [];
        foreach ($configured as $language => $words) {
            if (! is_string($language) || ! is_array($words)) {
                continue;
            }
            $out[$language] = array_values(array_filter($words, static fn ($w): bool => is_string($w) && $w !== ''));
        }

        return $out;
    }
}
