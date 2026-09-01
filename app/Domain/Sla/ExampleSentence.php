<?php

declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Sla\Clock\CalendarSnapshot;
use App\Domain\Sla\Clock\IntervalSet;
use App\Domain\Sla\Clock\SlaClock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Lang;

/**
 * Doc 12 §8 / §13 / §14 / AC-04 — the SLA policy editor's live example sentence
 * ("a High Deposits ticket created Tue 17:00 would target first response Wed
 * 08:40"), computed FROM THE CLOCK LIBRARY ITSELF — one implementation (AC-04's
 * clause: "the §8 example uses it"). §13 names these sentences the accessible
 * explanation of clock math; §14 makes them ICU-templated (workflow.sla.example,
 * en + am), tenant-timezone rendered with explicit labels.
 */
final class ExampleSentence
{
    public function __construct(private readonly SlaClock $clock) {}

    /**
     * The example's target instant: created at $createdAt, a $targetSeconds
     * business-hours target on $calendar (null = calendar-time). Null when the
     * calendar can never satisfy it (an empty week) — the editor renders the
     * unreachable notice instead of a fabricated date.
     *
     * @param  array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays?: list<array{date: string, recurring: bool}>}|null  $calendar
     */
    public function targetInstant(?array $calendar, int $targetSeconds, DateTimeImmutable $createdAt): ?DateTimeImmutable
    {
        $snapshot = $calendar === null ? null : CalendarSnapshot::fromArray($calendar);
        $at = $this->clock->instantWhenElapsedReaches(
            $targetSeconds * 1_000_000,
            IntervalSet::us($createdAt),
            $snapshot,
            new IntervalSet,
        );

        return $at === null ? null : IntervalSet::fromUs($at);
    }

    /**
     * @param  array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays?: list<array{date: string, recurring: bool}>}|null  $calendar
     */
    public function render(?array $calendar, int $targetSeconds, DateTimeImmutable $createdAt, DateTimeZone $displayTz, string $locale): string
    {
        $target = $this->targetInstant($calendar, $targetSeconds, $createdAt);
        if ($target === null) {
            return Lang::get('workflow.sla.example_unreachable', [], $locale);
        }

        return Lang::get('workflow.sla.example', [
            'created' => $createdAt->setTimezone($displayTz)->format('D H:i'),
            'target' => $target->setTimezone($displayTz)->format('D H:i'),
            'zone' => $displayTz->getName(),
        ], $locale);
    }
}
