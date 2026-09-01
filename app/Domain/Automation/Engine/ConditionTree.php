<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-02 — nested all/any groups over the FR-02 fact vocabulary, with the
 * PER-CONDITION TRUTH the dry-run drawer renders (§9: the ✓/✗ badge with the
 * evaluated value is "the single most useful debugging affordance, specified as
 * mandatory") — evaluate() therefore returns the trace, and FR-05's dry-run
 * reuses THIS evaluator verbatim (AC-02 parity by construction, not by copy).
 *
 * Operators per type (FR-02): equals, in, contains, gt, lt, between, is_empty.
 * `keyword_set` resolves the managed list at evaluation (reusable across rules).
 * Unknown facts evaluate as empty (EC-14: a retired field's condition is
 * is-empty, with the rule-level warning surfaced by the where-used machinery).
 */
final class ConditionTree
{
    /**
     * @param  array<string, mixed>  $node  {all: [...]} | {any: [...]} | {fact, op, value?}
     * @param  array<string, mixed>  $facts
     * @return array{matched: bool, trace: list<array{fact: string, op: string, value: mixed, actual: mixed, matched: bool}>}
     */
    public function evaluate(array $node, array $facts): array
    {
        if (isset($node['all']) && is_array($node['all'])) {
            return $this->group(array_values($node['all']), $facts, all: true);
        }
        if (isset($node['any']) && is_array($node['any'])) {
            return $this->group(array_values($node['any']), $facts, all: false);
        }

        $fact = is_string($node['fact'] ?? null) ? $node['fact'] : '';
        $op = is_string($node['op'] ?? null) ? $node['op'] : 'equals';
        $value = $node['value'] ?? null;
        $actual = $facts[$fact] ?? null;
        $matched = $this->leaf($op, $value, $actual);

        return ['matched' => $matched, 'trace' => [['fact' => $fact, 'op' => $op, 'value' => $value, 'actual' => $actual, 'matched' => $matched]]];
    }

    /**
     * @param  list<mixed>  $children
     * @param  array<string, mixed>  $facts
     * @return array{matched: bool, trace: list<array{fact: string, op: string, value: mixed, actual: mixed, matched: bool}>}
     */
    private function group(array $children, array $facts, bool $all): array
    {
        $matched = $all;
        $trace = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue; // malformed node — the writer's validation is the wall; evaluation stays total
            }
            /** @var array<string, mixed> $child */
            $result = $this->evaluate($child, $facts);
            $trace = array_merge($trace, $result['trace']);
            $matched = $all ? ($matched && $result['matched']) : ($matched || $result['matched']);
            // No short-circuit: FR-05's per-condition truth wants EVERY row's badge.
        }
        if ($children === []) {
            $matched = $all; // an empty ALL is vacuously true; an empty ANY is false
        }

        return ['matched' => $matched, 'trace' => $trace];
    }

    private function leaf(string $op, mixed $expected, mixed $actual): bool
    {
        return match ($op) {
            'equals' => is_array($actual) ? in_array($expected, $actual, false) : $actual === $expected,
            'in' => is_array($expected) && (is_array($actual)
                ? array_intersect(array_map(self::stringy(...), $actual), array_map(self::stringy(...), $expected)) !== []
                : in_array($actual, $expected, false)),
            'contains' => is_string($actual) && is_string($expected) && $expected !== ''
                && mb_stripos($actual, $expected) !== false,
            'keyword_set' => $this->keywordSetMatches($expected, $actual),
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'between' => is_numeric($actual) && is_array($expected) && count($expected) === 2
                && is_numeric($expected[0] ?? null) && is_numeric($expected[1] ?? null)
                && (float) $actual >= (float) $expected[0] && (float) $actual <= (float) $expected[1],
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            default => false,
        };
    }

    private static function stringy(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function keywordSetMatches(mixed $setId, mixed $actual): bool
    {
        if (! is_string($setId) || ! is_string($actual)) {
            return false;
        }
        $keywords = DB::table('automation_keyword_sets')->where('id', $setId)->where('active', true)->value('keywords');
        if (! is_string($keywords)) {
            return false;
        }
        /** @var list<string> $list */
        $list = json_decode($keywords, true, 4) ?: [];
        foreach ($list as $keyword) {
            if ($keyword !== '' && mb_stripos($actual, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
