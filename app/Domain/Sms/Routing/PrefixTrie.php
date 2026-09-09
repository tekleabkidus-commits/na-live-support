<?php

declare(strict_types=1);

namespace App\Domain\Sms\Routing;

/**
 * Doc 14 FR-08 / EC-01 / §12 — the in-memory prefix trie: longest-prefix match
 * over E.164 prefixes (`+251`, `+2519`, …), rebuilt on table change (≤ 0.5 ms
 * to resolve — SmsBudgetsTest). Pure: a digit-keyed tree of nodes, each
 * carrying the route id whose prefix ends there. "+2519 shadows +251" is a
 * TRIE fact: the longer prefix wins whatever the table's position says, which
 * is why the overlap warning exists (usually a config mistake, §9).
 */
final class PrefixTrie
{
    /** @var array<string, mixed> children keyed by character; '$' holds the route id */
    private array $root = [];

    public function insert(string $prefix, string $routeId): void
    {
        $node = &$this->root;
        foreach (str_split(ltrim($prefix, '+')) as $char) {
            if (! isset($node[$char]) || ! is_array($node[$char])) {
                $node[$char] = [];
            }
            $node = &$node[$char];
        }
        $node['$'] = $routeId;
    }

    /** @return array{route_id: string, prefix: string}|null the LONGEST prefix matching the phone */
    public function longest(string $phoneE164): ?array
    {
        $node = $this->root;
        $best = null;
        $walked = '';
        foreach (str_split(ltrim($phoneE164, '+')) as $char) {
            if (! isset($node[$char]) || ! is_array($node[$char])) {
                break;
            }
            $node = $node[$char];
            $walked .= $char;
            if (isset($node['$']) && is_string($node['$'])) {
                $best = ['route_id' => $node['$'], 'prefix' => '+'.$walked];
            }
        }

        return $best;
    }

    /**
     * EC-01 / §9: every (longer, shorter) pair where the longer prefix extends the
     * shorter one — "+2519 shadows +251 below it".
     *
     * @param  array<string, string>  $prefixes  route id → prefix
     * @return list<array{shadowing: string, shadowed: string}>
     */
    public static function overlaps(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $longer) {
            foreach ($prefixes as $shorter) {
                if ($longer !== $shorter && str_starts_with($longer, $shorter)) {
                    $out[] = ['shadowing' => $longer, 'shadowed' => $shorter];
                }
            }
        }

        return $out;
    }
}
