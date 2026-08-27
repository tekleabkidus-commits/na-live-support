<?php

declare(strict_types=1);

namespace App\Domain\Kb\Editor;

/**
 * Doc 10 SEC-03 — editor content is INERT everywhere: script/style/event handlers
 * and javascript: URLs are stripped AT SAVE from every string in the block document,
 * so no render surface (staff or customer) can be handed live markup. Articles are
 * less hostile than messages; the rule is the same.
 */
final class InertContent
{
    /**
     * @param  array<string, mixed>  $blocks
     * @return array<string, mixed>
     */
    public function sanitizeBlocks(array $blocks): array
    {
        array_walk_recursive($blocks, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->sanitizeString($value);
            }
        });

        return $blocks;
    }

    public function sanitizeString(string $value): string
    {
        $value = (string) preg_replace('#<\s*(script|style|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value);
        $value = (string) preg_replace('#<\s*/?\s*(script|style|iframe|object|embed)\b[^>]*>#i', '', $value);
        $value = (string) preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $value);

        return (string) preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*#i', '$1=$2#', $value);
    }
}
