<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * Sentence-boundary chunking (§13: streaming updates are announced at
 * sentence boundaries, never token-by-token; §9: reduced-motion renders in
 * chunks). Boundaries: Latin terminators (. ! ?) and the Ethiopic full stop
 * (። U+1362) / question mark (፧ U+1367), each followed by whitespace or end.
 * The trailing remainder (no terminator) is its own final chunk.
 */
final class SentenceChunker
{
    /** @return list<string> */
    public static function chunks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?\x{1362}\x{1367}])\s+/u', $text);
        if ($parts === false) {
            return [$text];
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Streaming buffer helper: given accumulated text, return [complete
     * sentences to emit, the remainder to keep buffering].
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function split(string $buffer): array
    {
        $chunks = self::chunks($buffer);
        if ($chunks === []) {
            return [[], ''];
        }
        // The last chunk is complete only when the buffer ends with a terminator.
        if (preg_match('/[.!?\x{1362}\x{1367}]\s*$/u', $buffer) === 1) {
            return [$chunks, ''];
        }
        $rest = array_pop($chunks);

        return [$chunks, $rest];
    }
}
