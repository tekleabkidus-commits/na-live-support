<?php

declare(strict_types=1);

namespace App\Domain\Sla\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Doc 12 FR-12 / T-05 / BR-03 — a RESOLVED business-hours calendar, the exact
 * shape `sla_instances` embeds (never a ref: an armed instance carries this
 * array verbatim, so later calendar edits are prospective by physics — EC-05).
 *
 * Snapshot contract (jsonb):
 *   {
 *     "timezone": "Africa/Addis_Ababa",
 *     "weekdays": { "1": [{"open":"08:00","close":"17:00"}, …], … },   // ISO-8601 weekday 1–7; split shifts = multiple entries
 *     "holidays": [ {"date":"2026-01-07","recurring":true}, … ]
 *   }
 * "24:00" as a close means end-of-day. Intervals never cross midnight (the
 * document's per-weekday model); a holiday removes its whole local day.
 * Pure: no DB, no wall clock — instants in, intervals out.
 */
final class CalendarSnapshot
{
    private DateTimeZone $tz;

    /** @var array<int, list<array{open: string, close: string}>> */
    private array $weekdays;

    /** @var list<array{date: string, recurring: bool}> */
    private array $holidays;

    /**
     * @param  array<int|string, list<array{open: string, close: string}>>  $weekdays
     * @param  list<array{date: string, recurring: bool}>  $holidays
     */
    private function __construct(DateTimeZone $tz, array $weekdays, array $holidays)
    {
        $normalized = [];
        foreach ($weekdays as $day => $intervals) {
            $n = (int) $day;
            if ($n < 1 || $n > 7) {
                throw new InvalidArgumentException(sprintf('weekday "%s" outside 1–7', (string) $day));
            }
            foreach ($intervals as $iv) {
                if (preg_match('/^\d{2}:\d{2}$/', $iv['open']) !== 1 || preg_match('/^\d{2}:\d{2}$/', $iv['close']) !== 1) {
                    throw new InvalidArgumentException('interval times must be HH:MM');
                }
                if ($iv['close'] !== '24:00' && $iv['close'] <= $iv['open']) {
                    throw new InvalidArgumentException('interval close must follow open (no cross-midnight intervals in v1)');
                }
            }
            $normalized[$n] = $intervals;
        }
        $this->tz = $tz;
        $this->weekdays = $normalized;
        $this->holidays = $holidays;
    }

    /**
     * @param  array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays?: list<array{date: string, recurring: bool}>}  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            new DateTimeZone($snapshot['timezone']),
            $snapshot['weekdays'],
            $snapshot['holidays'] ?? [],
        );
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    /**
     * Materialize the business windows intersecting [fromUs, untilUs) as an
     * IntervalSet of UTC instants. Local times are constructed per date in the
     * calendar's timezone, so a DST switch moves both bounds together and a
     * drawn window keeps its drawn local length.
     */
    public function windowsBetween(int $fromUs, int $untilUs): IntervalSet
    {
        if ($untilUs <= $fromUs) {
            return new IntervalSet;
        }
        $out = [];
        $day = IntervalSet::fromUs($fromUs)->setTimezone($this->tz)->setTime(0, 0);
        $untilLocal = IntervalSet::fromUs($untilUs)->setTimezone($this->tz);
        $guard = 0;
        while ($day <= $untilLocal) {
            if (++$guard > 5000) {
                throw new InvalidArgumentException('calendar span exceeds the 5000-day guard');
            }
            if (! $this->isHoliday($day)) {
                foreach ($this->weekdays[(int) $day->format('N')] ?? [] as $iv) {
                    $out[] = [
                        IntervalSet::us($this->at($day, $iv['open'])),
                        IntervalSet::us($this->at($day, $iv['close'])),
                    ];
                }
            }
            $day = $day->modify('+1 day');
        }

        return (new IntervalSet($out))->clip($fromUs, $untilUs);
    }

    private function at(DateTimeImmutable $localDay, string $hm): DateTimeImmutable
    {
        if ($hm === '24:00') {
            return $localDay->modify('+1 day');
        }
        [$h, $m] = [(int) substr($hm, 0, 2), (int) substr($hm, 3, 2)];

        return $localDay->setTime($h, $m);
    }

    private function isHoliday(DateTimeImmutable $localDay): bool
    {
        $ymd = $localDay->format('Y-m-d');
        $md = $localDay->format('m-d');
        foreach ($this->holidays as $h) {
            if ($h['recurring'] ? substr($h['date'], 5) === $md : $h['date'] === $ymd) {
                return true;
            }
        }

        return false;
    }
}
