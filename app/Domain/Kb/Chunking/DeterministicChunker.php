<?php

declare(strict_types=1);

namespace App\Domain\Kb\Chunking;

use App\Support\Settings\SettingsRepository;

/**
 * Doc 10 T-02 / FR-13 / FR-14 / EC-08 / §12 — the block-aware, DETERMINISTIC chunker:
 * chunk 0 is the FR-02 summary line; every later chunk carries its heading path as a
 * prefix ("Deposits > Limits"); blocks accumulate until ~kb.chunk_target_tokens
 * (whitespace tokens ≈ tokens, the same estimate for both launch scripts); a block
 * larger than the target is split at sentence boundaries. Same content → same chunks
 * → same hashes, so a minor edit re-embeds ONLY the chunks whose hash changed (the
 * cost fixture proves it). A 20,000-word article simply yields more chunks (EC-08).
 *
 * @phpstan-type Chunk array{index: int, heading_path: string, text: string, hash: string}
 */
final class DeterministicChunker
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @param  array<string, mixed>  $content  the block document
     * @return list<array{index: int, heading_path: string, text: string, hash: string}>
     */
    public function chunk(string $title, string $summary, array $content): array
    {
        $targetRaw = $this->settings->get('kb.chunk_target_tokens');
        $target = is_numeric($targetRaw) ? max(100, (int) $targetRaw) : 500;

        /** @var list<array{index: int, heading_path: string, text: string, hash: string}> $chunks */
        $chunks = [];
        $head = trim($summary) !== '' ? trim($summary) : trim($title);
        $chunks[] = $this->make(0, $title, $head);

        /** @var list<string> $path */
        $path = [$title];
        /** @var list<string> $buffer */
        $buffer = [];
        $bufferTokens = 0;
        $flush = function () use (&$chunks, &$buffer, &$bufferTokens, &$path): void {
            if (count($buffer) === 0) {
                return;
            }
            $chunks[] = $this->make(count($chunks), implode(' > ', $path), implode("\n", $buffer));
            $buffer = [];
            $bufferTokens = 0;
        };

        foreach (array_values(is_array($content['blocks'] ?? null) ? $content['blocks'] : []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            /** @var array<string, mixed> $block */
            $type = is_string($block['type'] ?? null) ? $block['type'] : 'paragraph';
            if ($type === 'heading') {
                $flush();
                $level = is_numeric($block['level'] ?? null) ? max(1, min(6, (int) $block['level'])) : 2;
                $path = array_slice($path, 0, $level - 1);
                $path[] = trim(is_string($block['text'] ?? null) ? $block['text'] : '');
                $path = array_values(array_filter($path, static fn (string $p): bool => $p !== ''));
                if ($path === []) {
                    $path = [$title];
                }

                continue;
            }
            $text = $this->blockText($block);
            if ($text === '') {
                continue;
            }
            foreach ($this->pieces($text, $target) as $piece) {
                $tokens = $this->tokens($piece);
                if ($bufferTokens > 0 && $bufferTokens + $tokens > $target) {
                    $flush();
                }
                $buffer[] = $piece;
                $bufferTokens += $tokens;
            }
        }
        $flush();

        return $chunks;
    }

    /** @return array{index: int, heading_path: string, text: string, hash: string} */
    private function make(int $index, string $headingPath, string $text): array
    {
        $headingPath = trim($headingPath);
        $body = $index === 0 ? $text : $headingPath."\n".$text;

        return ['index' => $index, 'heading_path' => $headingPath, 'text' => $body, 'hash' => hash('sha256', $body)];
    }

    /** @param array<string, mixed> $block */
    private function blockText(array $block): string
    {
        $parts = [];
        foreach (['text', 'caption', 'alt'] as $key) {
            if (is_string($block[$key] ?? null) && trim($block[$key]) !== '') {
                $parts[] = trim($block[$key]);
            }
        }
        foreach (['items', 'rows', 'cells'] as $key) {
            if (is_array($block[$key] ?? null)) {
                array_walk_recursive($block[$key], static function (mixed $v) use (&$parts): void {
                    if (is_string($v) && trim($v) !== '') {
                        $parts[] = trim($v);
                    }
                });
            }
        }

        return implode("\n", $parts);
    }

    /**
     * A block larger than the target splits at sentence boundaries, deterministically.
     *
     * @return list<string>
     */
    private function pieces(string $text, int $target): array
    {
        if ($this->tokens($text) <= $target) {
            return [$text];
        }
        $sentences = preg_split('/(?<=[.!?።፧])\s+/u', $text) ?: [$text];
        /** @var list<string> $out */
        $out = [];
        $current = '';
        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current.' '.$sentence;
            if ($current !== '' && $this->tokens($candidate) > $target) {
                $out[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    public function tokens(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
