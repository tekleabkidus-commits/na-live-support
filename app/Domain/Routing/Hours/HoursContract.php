<?php

declare(strict_types=1);

namespace App\Domain\Routing\Hours;

use App\Domain\Hours\CalendarReader;
use App\Domain\Sla\Clock\CalendarSnapshot;
use App\Domain\Sla\Clock\IntervalSet;
use App\Support\Settings\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-14 / FR-15 / EC-09 — the BUSINESS-HOURS contract. P-A53 LANDED
 * (S12 step 3, the countersigned source swap): Doc 12's real calendars are now
 * the FIRST source — department override > tenant default (CalendarReader) —
 * computed EXACTLY (split shifts + holidays) through the same CalendarSnapshot
 * the SLA clock reads. Where a tenant has painted NOTHING the behavior is
 * byte-identical to the seam: local/testing/ci read
 * `fixtures/routing/seams/doc12-hours.json`, production is ALWAYS OPEN — the
 * fixture PRE state is the packet's control (Doc 11 HoursTest's legs run
 * unchanged on a calendar-less tenant).
 *
 * The SURFACE is unchanged — six methods, same signatures, same semantics:
 * hours NEVER touch eligibility (EC-09); they feed the estimator band and the
 * pull-in expiry default (A-53: shift end = the drawn close of the current
 * tenant-local day when ahead, else 23:59:59 — with a real split-shift
 * calendar, "drawn close" is the LAST drawn close of today still ahead).
 * `timezone()` stays Doc 01's `tenant.display_timezone` (presentation);
 * calendar math runs in the CALENDAR's own timezone inside CalendarSnapshot.
 * `windowFor()` keeps its simple {open, close, days} projection — real
 * calendars project today's first open / last close; exact split-shift truth
 * lives in isOpen/nextOpen/shiftEnd, which every precise consumer uses.
 */
final class HoursContract
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CalendarReader $calendars,
    ) {}

    /**
     * @return array{open: string, close: string, days: list<int>}
     */
    public function windowFor(?string $departmentId): array
    {
        $snapshot = $this->resolved($departmentId);
        if ($snapshot !== null) {
            return $this->projectSimpleWindow($snapshot);
        }

        $alwaysOpen = ['open' => '00:00', 'close' => '24:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];
        if (! app()->environment(['local', 'testing', 'ci'])) {
            return $alwaysOpen;
        }
        $path = base_path('fixtures/routing/seams/doc12-hours.json');
        if (! is_file($path)) {
            return $alwaysOpen;
        }
        /** @var array{default?: array{open: string, close: string, days: list<int>}, department_overrides?: array<string, array{open: string, close: string, days: list<int>}>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $window = $fixture['default'] ?? $alwaysOpen;
        if ($departmentId !== null) {
            $slug = DB::table('departments')->where('id', $departmentId)->value('slug');
            if (is_string($slug) && isset($fixture['department_overrides'][$slug])) {
                $window = $fixture['department_overrides'][$slug];
            }
        }

        return $window;
    }

    /** The tenant's timezone (Doc 01 rules), UTC when unset/invalid. */
    public function timezone(): DateTimeZone
    {
        $name = $this->settings->get('tenant.display_timezone');
        try {
            return new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /** DB time (C-11) as an immutable instant. */
    public function now(): DateTimeImmutable
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('SELECT clock_timestamp() AS now');

        return new DateTimeImmutable($row->now);
    }

    /**
     * A-53: the pull-in expiry default — shift end = the drawn close of the current
     * tenant-local day when still ahead, else 23:59:59 tenant-local. Returned in UTC.
     */
    public function shiftEnd(?string $departmentId): DateTimeImmutable
    {
        $now = $this->now();
        $tz = $this->timezone();
        $nowLocal = $now->setTimezone($tz);
        $endOfDay = $nowLocal->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));

        $snapshot = $this->resolved($departmentId);
        if ($snapshot !== null) {
            // Today's drawn windows in the CALENDAR's zone; the last close still ahead.
            $calTz = $snapshot->timezone();
            $dayStart = $now->setTimezone($calTz)->setTime(0, 0);
            $windows = $snapshot->windowsBetween(IntervalSet::us($dayStart), IntervalSet::us($dayStart->modify('+1 day')));
            $nowUs = IntervalSet::us($now);
            foreach (array_reverse($windows->ranges()) as [, $end]) {
                if ($end > $nowUs) {
                    return IntervalSet::fromUs($end);
                }
            }

            return $endOfDay;
        }

        $window = $this->windowFor($departmentId);
        $close = $window['close'];
        if ($close !== '24:00' && preg_match('/^(\d{2}):(\d{2})$/', $close, $m) === 1) {
            $closeLocal = $nowLocal->setTime((int) $m[1], (int) $m[2], 0);
            if ($closeLocal > $nowLocal) {
                return $closeLocal->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return $endOfDay;
    }

    /** Whether the window is open at the given instant (tenant-local evaluation). */
    public function isOpen(?string $departmentId, DateTimeImmutable $at): bool
    {
        $snapshot = $this->resolved($departmentId);
        if ($snapshot !== null) {
            $us = IntervalSet::us($at);

            return ! $snapshot->windowsBetween($us, $us + 1)->isEmpty();
        }

        $window = $this->windowFor($departmentId);
        $local = $at->setTimezone($this->timezone());
        if (! in_array((int) $local->format('N'), $window['days'], true)) {
            return false;
        }
        $hm = $local->format('H:i');

        return $hm >= $window['open'] && ($window['close'] === '24:00' || $hm < $window['close']);
    }

    /**
     * FR-14's "when we open at HH:MM": the next opening instant at or after `$at` (tenant-local
     * evaluation, returned in UTC) — today's open if still ahead, else the next drawn day's.
     * An always-open window answers `$at` itself.
     */
    public function nextOpen(?string $departmentId, DateTimeImmutable $at): DateTimeImmutable
    {
        $snapshot = $this->resolved($departmentId);
        if ($snapshot !== null) {
            if ($this->isOpen($departmentId, $at)) {
                return $at;
            }
            $us = IntervalSet::us($at);
            // 400 days covers any holiday run; an empty calendar answers $at (the
            // always-open posture — nothing drawn means nothing to wait for).
            $windows = $snapshot->windowsBetween($us, $us + 400 * 86_400 * 1_000_000);
            foreach ($windows->ranges() as [$start]) {
                if ($start >= $us) {
                    return IntervalSet::fromUs($start);
                }
            }

            return $at;
        }

        $window = $this->windowFor($departmentId);
        $tz = $this->timezone();
        $local = $at->setTimezone($tz);
        if (preg_match('/^(\d{2}):(\d{2})$/', $window['open'], $m) !== 1) {
            return $at;
        }
        for ($day = 0; $day <= 7; $day++) {
            $candidate = $local->modify(sprintf('+%d day', $day))->setTime((int) $m[1], (int) $m[2], 0);
            if (! in_array((int) $candidate->format('N'), $window['days'], true)) {
                continue;
            }
            if ($candidate > $local || $this->isOpen($departmentId, $at)) {
                return ($candidate > $local ? $candidate : $local)->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return $at;
    }

    private function resolved(?string $departmentId): ?CalendarSnapshot
    {
        $snapshot = $this->calendars->resolvedFor($departmentId);

        return $snapshot === null ? null : CalendarSnapshot::fromArray($snapshot);
    }

    /**
     * @return array{open: string, close: string, days: list<int>}
     */
    private function projectSimpleWindow(CalendarSnapshot $snapshot): array
    {
        $calTz = $snapshot->timezone();
        $today = $this->now()->setTimezone($calTz)->setTime(0, 0);
        $windows = $snapshot->windowsBetween(IntervalSet::us($today), IntervalSet::us($today->modify('+1 day')));
        $ranges = $windows->ranges();
        $open = '00:00';
        $close = '24:00';
        if ($ranges !== []) {
            $open = IntervalSet::fromUs($ranges[0][0])->setTimezone($calTz)->format('H:i');
            $lastEnd = IntervalSet::fromUs($ranges[count($ranges) - 1][1])->setTimezone($calTz);
            $close = $lastEnd->format('H:i') === '00:00' ? '24:00' : $lastEnd->format('H:i');
        }
        $days = [];
        for ($n = 1; $n <= 7; $n++) {
            $probe = $today->modify(sprintf('+%d day', ($n - (int) $today->format('N') + 7) % 7));
            if (! $snapshot->windowsBetween(IntervalSet::us($probe), IntervalSet::us($probe->modify('+1 day')))->isEmpty()) {
                $days[] = $n;
            }
        }

        return ['open' => $open, 'close' => $close, 'days' => $days];
    }
}
