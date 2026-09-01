<?php

declare(strict_types=1);

use App\Domain\Sla\Clock\CalendarSnapshot;
use App\Domain\Sla\Clock\IntervalSet;
use App\Domain\Sla\Clock\SlaClock;

/*
|--------------------------------------------------------------------------
| Doc 12 T-04 / FR-10 / EC-03 / EC-04 / AC-03 — THE SLA CLOCK IS PHYSICS (property test)
|--------------------------------------------------------------------------
|
| Written FIRST (S12 plan step 1, §21 recommendation 1) — no engine line exists
| before this file is green. Pure: no DB, no wall clock, no tenant. Fixed seed
| so a red is reproducible.
|
|  P1  EC-04 PAUSE UNION — for any generated set of OVERLAPPING pauses, elapsed
|      equals active_total − |union ∩ active|, where the union is computed by an
|      INDEPENDENT event-sweep in this file (never IntervalSet — that would be
|      vacuous). Overlap can never double-subtract.
|  P2  MONOTONICITY — extending the active interval never decreases elapsed.
|  P3  CALENDAR BOUND — elapsed never exceeds raw active time; a 24/7 calendar
|      equals calendar-time mode (null) exactly.
|  P4  EC-03 RETARGET — remaining_new = remaining_pct × target_new: the
|      proportion is preserved within integer rounding, in both directions.
|  P5  INVERSE CONSISTENCY — the instant instantWhenElapsedReaches() returns is
|      the instant at which elapsedMicroseconds() over [from, t*] equals the
|      target EXACTLY (µs) — next-fire-at and the clock can never disagree.
|  G   GOLDEN TIMELINES — fixtures/sla/golden-timelines.json, the shared Doc 15
|      contract: hand-computed expecteds (US-02's §8 example, split shifts,
|      recurring holiday, EC-04, DST window length) reproduced to the second.
|
| Sabotage (j) of the plan: subtraction switched to a raw non-normalized pause
| list → P1 red at some overlapping stack (and golden pause-union-ec04 red).
*/

const SLA_SEED = 1201;

const HOUR_US = 3_600_000_000;

function isoUs(string $iso): int
{
    return IntervalSet::us(new DateTimeImmutable($iso));
}

/** Independent union length: event sweep over depth, clipped to the active range. */
function sweepUnionWithin(array $pauses, int $from, int $until): int
{
    $events = [];
    foreach ($pauses as [$s, $e]) {
        $events[] = [max($s, $from), 1];
        $events[] = [min($e, $until), -1];
    }
    usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
    $depth = 0;
    $covered = 0;
    $openedAt = 0;
    foreach ($events as [$at, $delta]) {
        $at = max($from, min($at, $until));
        if ($depth === 0 && $delta === 1) {
            $openedAt = $at;
        }
        $depth += $delta;
        if ($depth === 0 && $delta === -1 && $at > $openedAt) {
            $covered += $at - $openedAt;
        }
    }

    return $covered;
}

it('P1: overlapping pauses subtract as a union, never twice (EC-04)', function () {
    mt_srand(SLA_SEED);
    $clock = new SlaClock;
    for ($round = 0; $round < 200; $round++) {
        $from = isoUs('2026-09-01T00:00:00Z') + mt_rand(0, 24) * HOUR_US;
        $until = $from + mt_rand(2, 48) * HOUR_US;
        $active = new IntervalSet([[$from, $until]]);
        $pauses = [];
        $n = mt_rand(0, 6);
        for ($i = 0; $i < $n; $i++) {
            $s = $from + mt_rand(-4, 44) * HOUR_US / 2;
            $pauses[] = [(int) $s, (int) $s + mt_rand(1, 12) * HOUR_US / 2];
        }
        $elapsed = $clock->elapsedMicroseconds($active, null, new IntervalSet($pauses));
        $expected = ($until - $from) - sweepUnionWithin($pauses, $from, $until);
        expect($elapsed)->toBe($expected);
    }
});

it('P2: extending the active interval never decreases elapsed', function () {
    mt_srand(SLA_SEED + 1);
    $clock = new SlaClock;
    $calendar = CalendarSnapshot::fromArray([
        'timezone' => 'Africa/Addis_Ababa',
        'weekdays' => [1 => [['open' => '08:00', 'close' => '17:00']], 3 => [['open' => '09:00', 'close' => '12:00']], 5 => [['open' => '08:00', 'close' => '24:00']]],
        'holidays' => [['date' => '2026-09-11', 'recurring' => false]],
    ]);
    for ($round = 0; $round < 60; $round++) {
        $from = isoUs('2026-09-01T00:00:00Z') + mt_rand(0, 96) * HOUR_US;
        $pauses = new IntervalSet([[$from + HOUR_US, $from + 3 * HOUR_US]]);
        $prev = 0;
        for ($h = 1; $h <= 96; $h += mt_rand(1, 9)) {
            $elapsed = $clock->elapsedMicroseconds(new IntervalSet([[$from, $from + $h * HOUR_US]]), $calendar, $pauses);
            expect($elapsed)->toBeGreaterThanOrEqual($prev);
            $prev = $elapsed;
        }
    }
});

it('P3: elapsed is bounded by raw active time, and a 24/7 calendar equals calendar-time mode', function () {
    mt_srand(SLA_SEED + 2);
    $clock = new SlaClock;
    $sevenDays = [];
    foreach (range(1, 7) as $d) {
        $sevenDays[$d] = [['open' => '00:00', 'close' => '24:00']];
    }
    $alwaysOpen = CalendarSnapshot::fromArray(['timezone' => 'UTC', 'weekdays' => $sevenDays, 'holidays' => []]);
    for ($round = 0; $round < 100; $round++) {
        $from = isoUs('2026-09-01T00:00:00Z') + mt_rand(0, 72) * HOUR_US;
        $active = new IntervalSet([[$from, $from + mt_rand(1, 72) * HOUR_US]]);
        $pauses = new IntervalSet([[$from + mt_rand(0, 5) * HOUR_US, $from + mt_rand(6, 20) * HOUR_US]]);
        $null = $clock->elapsedMicroseconds($active, null, $pauses);
        $open = $clock->elapsedMicroseconds($active, $alwaysOpen, $pauses);
        expect($open)->toBe($null)
            ->and($null)->toBeLessThanOrEqual($active->totalMicroseconds())
            ->and($null)->toBeGreaterThanOrEqual(0);
    }
});

it('P4: the EC-03 retarget formula preserves the remaining proportion, both directions', function () {
    mt_srand(SLA_SEED + 3);
    $clock = new SlaClock;
    for ($round = 0; $round < 300; $round++) {
        $old = mt_rand(60, 86_400) * 1_000_000;
        $new = mt_rand(60, 86_400) * 1_000_000;
        $remaining = mt_rand(0, (int) ($old / 1_000_000)) * 1_000_000;
        $result = $clock->retargetRemaining($remaining, $old, $new);
        expect(abs($result / $new - $remaining / $old))->toBeLessThan(1e-6)
            ->and($result)->toBeGreaterThanOrEqual(0)
            ->and($result)->toBeLessThanOrEqual($new);
    }
});

it('P5: next-fire-at and the elapsed clock can never disagree (inverse consistency)', function () {
    mt_srand(SLA_SEED + 4);
    $clock = new SlaClock;
    $calendar = CalendarSnapshot::fromArray([
        'timezone' => 'Africa/Addis_Ababa',
        'weekdays' => [1 => [['open' => '08:00', 'close' => '17:00']], 2 => [['open' => '08:00', 'close' => '12:00'], ['open' => '14:00', 'close' => '17:00']], 4 => [['open' => '08:00', 'close' => '17:00']]],
        'holidays' => [['date' => '2026-09-14', 'recurring' => false]],
    ]);
    foreach ([null, $calendar] as $cal) {
        for ($round = 0; $round < 60; $round++) {
            $from = isoUs('2026-09-01T00:00:00Z') + mt_rand(0, 120) * HOUR_US;
            $pauses = new IntervalSet([[$from + mt_rand(1, 10) * HOUR_US, $from + mt_rand(11, 30) * HOUR_US]]);
            $target = mt_rand(1, 40) * HOUR_US;
            $fireAt = $clock->instantWhenElapsedReaches($target, $from, $cal, $pauses);
            expect($fireAt)->not->toBeNull();
            $elapsedAtFire = $clock->elapsedMicroseconds(new IntervalSet([[$from, $fireAt]]), $cal, $pauses);
            expect($elapsedAtFire)->toBe($target);
        }
    }
});

it('P5b: an unreachable target answers null, an already-met target answers the from-instant', function () {
    $clock = new SlaClock;
    $emptyCalendar = CalendarSnapshot::fromArray(['timezone' => 'UTC', 'weekdays' => [], 'holidays' => []]);
    $from = isoUs('2026-09-01T00:00:00Z');
    expect($clock->instantWhenElapsedReaches(HOUR_US, $from, $emptyCalendar, new IntervalSet))->toBeNull()
        ->and($clock->instantWhenElapsedReaches(HOUR_US, $from, null, new IntervalSet, 2 * HOUR_US))->toBe($from);
});

it('G: the golden timelines reproduce to the second (the shared Doc 15 contract)', function () {
    $clock = new SlaClock;
    /** @var array{calendars: array<string, array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}>, timelines: list<array{name: string, calendar: ?string, clock_start: string, pauses: list<array{from: string, until: string}>, target_s: int, warning_pct: int, expected_warning_at: ?string, expected_breach_at: ?string, probes: list<array{at: string, expected_elapsed_s: int}>}>, retarget_cases: list<array{name: string, remaining_s: int, old_target_s: int, new_target_s: int, expected_remaining_s: int}>} $golden */
    $golden = json_decode((string) file_get_contents(base_path('fixtures/sla/golden-timelines.json')), true, 64, JSON_THROW_ON_ERROR);

    foreach ($golden['timelines'] as $t) {
        $calendar = $t['calendar'] === null ? null : CalendarSnapshot::fromArray($golden['calendars'][$t['calendar']]);
        $start = isoUs($t['clock_start']);
        $pauses = new IntervalSet(array_map(
            fn (array $p): array => [isoUs($p['from']), isoUs($p['until'])],
            $t['pauses'],
        ));
        foreach ($t['probes'] as $probe) {
            $elapsed = $clock->elapsedMicroseconds(new IntervalSet([[$start, isoUs($probe['at'])]]), $calendar, $pauses);
            expect($elapsed)->toBe($probe['expected_elapsed_s'] * 1_000_000, "{$t['name']} probe {$probe['at']}");
        }
        $targetUs = $t['target_s'] * 1_000_000;
        if ($t['expected_warning_at'] !== null) {
            $warnAt = $clock->instantWhenElapsedReaches($clock->warningThresholdUs($targetUs, $t['warning_pct']), $start, $calendar, $pauses);
            expect($warnAt)->toBe(isoUs($t['expected_warning_at']), "{$t['name']} warning");
        }
        if ($t['expected_breach_at'] !== null) {
            $breachAt = $clock->instantWhenElapsedReaches($targetUs, $start, $calendar, $pauses);
            expect($breachAt)->toBe(isoUs($t['expected_breach_at']), "{$t['name']} breach");
        }
    }

    foreach ($golden['retarget_cases'] as $c) {
        expect($clock->retargetRemaining($c['remaining_s'] * 1_000_000, $c['old_target_s'] * 1_000_000, $c['new_target_s'] * 1_000_000))
            ->toBe($c['expected_remaining_s'] * 1_000_000, $c['name']);
    }
});
