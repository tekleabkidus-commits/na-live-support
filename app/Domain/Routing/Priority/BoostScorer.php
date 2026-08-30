<?php

declare(strict_types=1);

namespace App\Domain\Routing\Priority;

use DateTimeImmutable;

/**
 * Doc 11 FR-08 step 3 / FR-12 — the effective priority score (A-51).
 *
 * base = FIFO by queued_at (DB time); boosts = the queue's ORDERED priority_rules
 * (FR-12: urgent/high priority, VIP, reply-window closing, SLA at-risk [D12 input],
 * reopened). Each matched rule adds a weight by its list position (first = largest),
 * "additive score; each boost logged". The starvation cap is deliberately NOT here
 * (StarvationCap — a head promotion outside the score, NFR-06).
 *
 * Pure: no clock, no I/O. Inputs are values; the output is a score plus the list
 * of applied boosts for the routing record (FR-17 `boosts_applied`).
 */
final class BoostScorer
{
    /** Weight of the i-th rule (0-based) in an ordered list of n rules. */
    public static function weight(int $position, int $ruleCount): int
    {
        return ($ruleCount - $position) * 1_000;
    }

    /**
     * @param  list<string>  $orderedRules  the queue's priority_rules, in order
     * @param  list<string>  $matchedRules  the rules that match this ticket
     * @return array{score: int, boosts_applied: list<string>, age_seconds: int}
     */
    public function score(array $orderedRules, array $matchedRules, DateTimeImmutable $queuedAt, DateTimeImmutable $now): array
    {
        $score = 0;
        $applied = [];
        foreach ($orderedRules as $i => $rule) {
            if (in_array($rule, $matchedRules, true)) {
                $score += self::weight($i, count($orderedRules));
                $applied[] = $rule;
            }
        }
        // The age term keeps FIFO among equals: one point per second waited,
        // bounded so that a full boost tier is never crossed by age alone.
        $age = max(0, $now->getTimestamp() - $queuedAt->getTimestamp());
        $score += min($age, 999);

        return ['score' => $score, 'boosts_applied' => $applied, 'age_seconds' => $age];
    }
}
