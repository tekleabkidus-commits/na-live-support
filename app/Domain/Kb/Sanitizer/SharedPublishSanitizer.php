<?php

declare(strict_types=1);

namespace App\Domain\Kb\Sanitizer;

use App\Domain\Ai\Prompt\InstructionStripper;

/**
 * Doc 10 T-03 / FR-13 / BR-06 / EC-09 / AC-05 — ONE SHARED PACKAGE with Doc 09: this
 * sanitizer runs Doc 09's index-time InstructionStripper (its PATTERNS are the single
 * rule set) SENTENCE BY SENTENCE over every text-bearing block, so a flag names the
 * offending sentence ("this sentence may be misread as an instruction to the AI") for
 * the publisher's keep/edit/override decision (§8/§9 inline warnings). No second rule
 * set, no second implementation: a sentence flags here iff the stripper would strip it
 * at index time. EC-09: user-directed imperatives ("click Deposit") do not match the
 * stripper's assistant-directed patterns, so guide-style articles yield ~zero flags —
 * the fixture suite asserts it.
 */
final class SharedPublishSanitizer implements PublishSanitizer
{
    public function __construct(private readonly InstructionStripper $stripper) {}

    public function scan(array $blocks, string $language): array
    {
        /** @var list<array{block: int, sentence: string, pattern: string}> $flags */
        $flags = [];
        $list = is_array($blocks['blocks'] ?? null) ? array_values($blocks['blocks']) : [];
        foreach ($list as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            /** @var array<string, mixed> $typedBlock */
            $typedBlock = $block;
            foreach ($this->texts($typedBlock) as $text) {
                foreach ($this->sentences($text) as $sentence) {
                    $result = $this->stripper->strip($sentence);
                    if ($result['stripped'] > 0) {
                        $flags[] = ['block' => $index, 'sentence' => $sentence, 'pattern' => $this->patternLabel($sentence, $result['content'])];
                    }
                }
            }
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function texts(array $block): array
    {
        /** @var list<string> $out */
        $out = [];
        foreach (['text', 'title', 'caption', 'alt', 'summary'] as $key) {
            if (is_string($block[$key] ?? null) && trim($block[$key]) !== '') {
                $out[] = $block[$key];
            }
        }
        foreach (['items', 'rows', 'cells'] as $key) {
            if (is_array($block[$key] ?? null)) {
                array_walk_recursive($block[$key], static function (mixed $v) use (&$out): void {
                    if (is_string($v) && trim($v) !== '') {
                        $out[] = $v;
                    }
                });
            }
        }

        return $out;
    }

    /**
     * Sentence split for both launch scripts: Latin terminators and the Ethiopic ። ፧ ! ?
     *
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?።፧])\s+|\n+/u', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /** The stripped span, for the popover's "flagged pattern" (the stripper removes it; the difference names it). */
    private function patternLabel(string $sentence, string $stripped): string
    {
        $a = preg_split('/\s+/u', $sentence) ?: [];
        $b = array_flip(preg_split('/\s+/u', $stripped) ?: []);
        $removed = array_values(array_filter($a, static fn (string $w): bool => ! isset($b[$w])));

        return mb_substr(implode(' ', $removed), 0, 120);
    }
}
