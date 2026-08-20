<?php

declare(strict_types=1);

namespace App\Domain\Ai\Pipeline;

/**
 * FR-11/FR-12's threshold arithmetic as ONE pure function, shared by the
 * live pipeline and the simulation engine — AC-03's parity is a property
 * of sharing this function, not of two implementations agreeing by luck.
 *
 *   rg_risk >= rg_min        -> rg_override     (outranks everything)
 *   spam >= spam_min         -> spam            (no chatbot turn)
 *   confidence < handoff_below -> handoff_low_confidence
 *   otherwise                -> eligible        (the turn proceeds; an answer
 *                               with confidence >= auto_resolve_min may
 *                               auto-resolve, else it answers and waits)
 */
final class RoutePolicy
{
    /**
     * @param  array<string, mixed>  $thresholds
     * @return 'rg_override'|'spam'|'handoff_low_confidence'|'eligible_auto_resolve'|'eligible'
     */
    public static function outcomeFor(float $confidence, float $rgRisk, float $spam, array $thresholds): string
    {
        if ($rgRisk >= self::threshold($thresholds, 'rg_min', 0.8)) {
            return 'rg_override';
        }
        if ($spam >= self::threshold($thresholds, 'spam_min', 0.9)) {
            return 'spam';
        }
        if ($confidence < self::threshold($thresholds, 'handoff_below', 0.6)) {
            return 'handoff_low_confidence';
        }

        return $confidence >= self::threshold($thresholds, 'auto_resolve_min', 0.85) ? 'eligible_auto_resolve' : 'eligible';
    }

    /** @param  array<string, mixed>  $thresholds */
    private static function threshold(array $thresholds, string $key, float $default): float
    {
        $value = $thresholds[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }
}
