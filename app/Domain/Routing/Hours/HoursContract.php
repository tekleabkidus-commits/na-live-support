<?php

declare(strict_types=1);

namespace App\Domain\Routing\Hours;

use App\Support\Settings\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-14 / FR-15 / EC-09 — the BUSINESS-HOURS SEAM, [D12] (A-53, filed).
 *
 * Doc 12's calendars are unbuilt. This fixture-shaped contract is what routing reads:
 * local/testing/ci read `fixtures/routing/seams/doc12-hours.json`; production is
 * ALWAYS OPEN (no calendar) until Doc 12 lands — then the SOURCE of the window swaps
 * and no caller changes. Hours NEVER touch eligibility (EC-09 — `RoutableAgents` takes
 * no HoursContract, EligibilityTest pins it): they feed the estimator band (step 8) and
 * the pull-in expiry default (FR-15: "expiry default shift-end via business hours").
 *
 * A-53's default: the tenant-timezone END OF THE CURRENT DAY — the window's close when
 * one is drawn and still ahead, 23:59:59 tenant-local otherwise. "Now" is the DATABASE's
 * clock (C-11), never the app server's; the tenant timezone is Doc 01's
 * `tenant.display_timezone` setting (UTC when unset or invalid — a fallback, logged by
 * the setting layer, never a silent shift of the stored UTC instant).
 */
final class HoursContract
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array{open: string, close: string, days: list<int>}
     */
    public function windowFor(?string $departmentId): array
    {
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
        $tz = $this->timezone();
        $nowLocal = $this->now()->setTimezone($tz);
        $window = $this->windowFor($departmentId);

        $endOfDay = $nowLocal->setTime(23, 59, 59);
        $close = $window['close'];
        if ($close !== '24:00' && preg_match('/^(\d{2}):(\d{2})$/', $close, $m) === 1) {
            $closeLocal = $nowLocal->setTime((int) $m[1], (int) $m[2], 0);
            if ($closeLocal > $nowLocal) {
                return $closeLocal->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return $endOfDay->setTimezone(new DateTimeZone('UTC'));
    }

    /** Whether the window is open at the given instant (tenant-local evaluation). */
    public function isOpen(?string $departmentId, DateTimeImmutable $at): bool
    {
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
}
