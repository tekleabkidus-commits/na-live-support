<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Outbound;

/**
 * FR-16: text > max_text splits at WORD BOUNDARIES into sequential parts,
 * each annotated "part i/n". The lane sends the parts in order (Doc 04 FR-15
 * lanes); EC-09 — a mid-split failure holds the lane and retry RESUMES from
 * the failed part, never from part 1 (sabotage #15 pins this).
 *
 * Pure and deterministic: given text + limit it yields the same parts, so a
 * resume recomputes the exact segment list and picks up at the failed index.
 */
final class MessageSplitter
{
    /**
     * @return list<array{index: int, total: int, text: string}> one entry per part
     */
    public static function split(string $text, int $limit): array
    {
        // The "part i/n" annotation costs characters, so the content budget
        // per part is smaller than the raw limit. We reserve a fixed margin
        // big enough for "\n\n(part NN/NN)".
        $margin = 16;
        $budget = max(1, $limit - $margin);

        if (mb_strlen($text) <= $limit) {
            return [['index' => 1, 'total' => 1, 'text' => $text]];
        }

        $segments = self::segment($text, $budget);
        $total = count($segments);

        $parts = [];
        foreach ($segments as $i => $segment) {
            $index = $i + 1;
            $parts[] = [
                'index' => $index,
                'total' => $total,
                'text' => $segment.sprintf("\n\n(part %d/%d)", $index, $total),
            ];
        }

        return $parts;
    }

    /**
     * Greedy word-boundary packing; a single word longer than the budget is
     * hard-split (never dropped).
     *
     * @return list<string>
     */
    private static function segment(string $text, int $budget): array
    {
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        $segments = [];
        $current = '';

        foreach ($words as $token) {
            if ($token === '') {
                continue;
            }

            while (mb_strlen($token) > $budget) {
                // A single oversize word: flush, then hard-cut it.
                if ($current !== '') {
                    $segments[] = rtrim($current);
                    $current = '';
                }
                $segments[] = mb_substr($token, 0, $budget);
                $token = mb_substr($token, $budget);
            }

            if (mb_strlen($current) + mb_strlen($token) > $budget) {
                $segments[] = rtrim($current);
                $current = ltrim($token);
            } else {
                $current .= $token;
            }
        }

        if (trim($current) !== '') {
            $segments[] = rtrim($current);
        }

        return $segments;
    }
}
