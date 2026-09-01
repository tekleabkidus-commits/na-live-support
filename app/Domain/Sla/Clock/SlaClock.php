<?php

declare(strict_types=1);

namespace App\Domain\Sla\Clock;

use InvalidArgumentException;

/**
 * Doc 12 FR-10 / T-04 / EC-03 / EC-04 / AC-03 — THE SLA CLOCK, a pure library.
 *
 * Built FIRST (§21 recommendation 1): both Doc 07's chips and Doc 15's metrics
 * stand on these functions, so they are property-tested physics, not
 * configuration. No DB, no wall clock, no tenant context — instants in,
 * instants out. The normative math (FR-10):
 *
 *   elapsed = Σ (active ∩ business-hours calendar) − (paused-interval UNION)
 *
 * A null calendar is CALENDAR-TIME mode (the clock runs 24/7). Pauses subtract
 * as a union by IntervalSet's canonical-form physics — overlapping pause
 * conditions can never double-subtract (EC-04). The retarget formula is
 * documented physics (EC-03): remaining_new = remaining_pct × target_new.
 * Warning fires at threshold% of target, breach at 100% (FR-10); the inverse
 * function (instantWhenElapsedReaches) is what the timer layer arms next-fire-at
 * from (T-04's "no per-second ticking").
 */
final class SlaClock
{
    /** The forward-walk horizon for next-fire computation: ~3 years of chunks. */
    private const HORIZON_CHUNK_US = 30 * 86_400 * 1_000_000; // 30 days

    private const HORIZON_CHUNKS = 37;

    /**
     * FR-10's elapsed, in microseconds.
     */
    public function elapsedMicroseconds(IntervalSet $active, ?CalendarSnapshot $calendar, IntervalSet $pauses): int
    {
        if ($active->isEmpty()) {
            return 0;
        }
        $running = $active;
        if ($calendar !== null) {
            $ranges = $active->ranges();
            $windows = $calendar->windowsBetween($ranges[0][0], $ranges[count($ranges) - 1][1]);
            $running = $active->intersect($windows);
        }

        return $running->subtract($pauses)->totalMicroseconds();
    }

    /**
     * The inverse: the earliest instant ≥ $fromUs at which total elapsed reaches
     * $targetUs, given $alreadyElapsedUs accrued before $fromUs and assuming the
     * clock RUNS from $fromUs onward except where $pauses cover it. Returns null
     * when the target is unreachable inside the ~3-year horizon (e.g. an empty
     * calendar) — the caller decides what an unreachable deadline means; the
     * timer layer treats it as "nothing to arm".
     */
    public function instantWhenElapsedReaches(
        int $targetUs,
        int $fromUs,
        ?CalendarSnapshot $calendar,
        IntervalSet $pauses,
        int $alreadyElapsedUs = 0,
    ): ?int {
        if ($targetUs < 0 || $alreadyElapsedUs < 0) {
            throw new InvalidArgumentException('negative durations are not instants');
        }
        $remaining = $targetUs - $alreadyElapsedUs;
        if ($remaining <= 0) {
            return $fromUs;
        }
        $cursor = $fromUs;
        for ($chunk = 0; $chunk < self::HORIZON_CHUNKS; $chunk++) {
            $until = $cursor + self::HORIZON_CHUNK_US;
            $windows = $calendar === null
                ? new IntervalSet([[$cursor, $until]])
                : $calendar->windowsBetween($cursor, $until);
            foreach ($windows->subtract($pauses)->ranges() as [$s, $e]) {
                $span = $e - $s;
                if ($span >= $remaining) {
                    return $s + $remaining;
                }
                $remaining -= $span;
            }
            $cursor = $until;
        }

        return null;
    }

    /**
     * EC-03's documented physics: remaining_new = remaining_pct × target_new.
     * Positions the retargeted stage at the SAME fraction of its new target that
     * it had left of the old one ("keep the proportion, not the minutes").
     */
    public function retargetRemaining(int $remainingUs, int $oldTargetUs, int $newTargetUs): int
    {
        if ($oldTargetUs <= 0 || $newTargetUs <= 0) {
            throw new InvalidArgumentException('targets must be positive durations');
        }
        if ($remainingUs < 0) {
            $remainingUs = 0;
        }

        return (int) round(($remainingUs / $oldTargetUs) * $newTargetUs);
    }

    /** FR-10: the warning threshold as elapsed microseconds ($pct of target). */
    public function warningThresholdUs(int $targetUs, int $warningPct): int
    {
        if ($warningPct < 1 || $warningPct > 100) {
            throw new InvalidArgumentException('warning threshold is a percentage 1–100');
        }

        return (int) round($targetUs * $warningPct / 100);
    }
}
