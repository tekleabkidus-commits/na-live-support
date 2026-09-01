<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * Doc 12 FR-20 / T-07 — THE ONE evaluator for form conditional logic
 * (`visible_when` = {field, op, value?}). SubmissionWriter's server-truth walk
 * and the Telegram flow engine both call THIS — visibility can never mean two
 * things (the no-second-implementation law). Strict comparisons: schema values
 * and answers are both JSON strings for choice fields, numerics compare
 * numerically via gt/lt/between.
 */
final class ConditionEval
{
    /**
     * @param  array<mixed>  $when  untrusted schema JSON — every read is guarded
     * @param  array<string, mixed>  $answers
     */
    public static function holds(array $when, array $answers): bool
    {
        $field = is_string($when['field'] ?? null) ? $when['field'] : '';
        $value = $answers[$field] ?? null;
        $target = $when['value'] ?? null;

        return match ($when['op'] ?? null) {
            'equals' => $value === $target,
            'not_equals' => $value !== $target,
            'in' => is_array($target) && in_array($value, $target, false),
            'contains' => is_string($value) && is_string($target) && str_contains($value, $target),
            'gt' => is_numeric($value) && is_numeric($target) && (float) $value > (float) $target,
            'lt' => is_numeric($value) && is_numeric($target) && (float) $value < (float) $target,
            'between' => is_numeric($value) && is_array($target) && count($target) === 2
                && is_numeric($target[0] ?? null) && is_numeric($target[1] ?? null)
                && (float) $value >= (float) $target[0] && (float) $value <= (float) $target[1],
            'is_empty' => $value === null || $value === '' || $value === [],
            'not_empty' => ! ($value === null || $value === '' || $value === []),
            default => false,
        };
    }

    /** A field's answer key: the ref for a custom field, the key for an inline one.
     * @param  array<mixed>  $field */
    public static function keyOf(array $field): ?string
    {
        return is_string($field['ref'] ?? null) ? $field['ref'] : (is_string($field['key'] ?? null) ? $field['key'] : null);
    }

    /** Whether a field is visible given the answers so far.
     * @param  array<mixed>  $field
     * @param  array<string, mixed>  $answers */
    public static function visible(array $field, array $answers): bool
    {
        return ! is_array($field['visible_when'] ?? null) || self::holds($field['visible_when'], $answers);
    }
}
