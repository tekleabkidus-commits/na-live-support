<?php

declare(strict_types=1);

namespace App\Domain\Kb\Versioning;

/**
 * Doc 10 FR-08 / §9 — block-aware diff between two content snapshots: blocks pair by
 * their stable `id` when present (the editor assigns one per block), else by position;
 * the result lists added / removed / changed (old+new) / unchanged in output order,
 * so the diff view navigates by changed-block landmarks (§13). Deterministic (T-02).
 *
 * @phpstan-type Block array<string, mixed>
 */
final class BlockDiff
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{summary: array{added: int, removed: int, changed: int, unchanged: int}, entries: list<array{kind: string, key: string, old: array<string, mixed>|null, new: array<string, mixed>|null}>}
     */
    public function diff(array $old, array $new): array
    {
        $oldBlocks = $this->keyed($old);
        $newBlocks = $this->keyed($new);
        /** @var list<array{kind: string, key: string, old: array<string, mixed>|null, new: array<string, mixed>|null}> $entries */
        $entries = [];
        $summary = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];
        foreach ($newBlocks as $key => $block) {
            if (! array_key_exists($key, $oldBlocks)) {
                $entries[] = ['kind' => 'added', 'key' => $key, 'old' => null, 'new' => $block];
                $summary['added']++;
            } elseif ($this->hash($oldBlocks[$key]) !== $this->hash($block)) {
                $entries[] = ['kind' => 'changed', 'key' => $key, 'old' => $oldBlocks[$key], 'new' => $block];
                $summary['changed']++;
            } else {
                $entries[] = ['kind' => 'unchanged', 'key' => $key, 'old' => $block, 'new' => $block];
                $summary['unchanged']++;
            }
        }
        foreach ($oldBlocks as $key => $block) {
            if (! array_key_exists($key, $newBlocks)) {
                $entries[] = ['kind' => 'removed', 'key' => $key, 'old' => $block, 'new' => null];
                $summary['removed']++;
            }
        }

        return ['summary' => $summary, 'entries' => $entries];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $content): array
    {
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];
        $out = [];
        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $key = is_string($block['id'] ?? null) && $block['id'] !== '' ? 'id:'.$block['id'] : 'pos:'.$index;
            /** @var array<string, mixed> $block */
            $out[$key] = $block;
        }

        return $out;
    }

    /** @param array<string, mixed> $block */
    public function hash(array $block): string
    {
        unset($block['id']);
        ksort($block);

        return hash('sha256', json_encode($block, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
