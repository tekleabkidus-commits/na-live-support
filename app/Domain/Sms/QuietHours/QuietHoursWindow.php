<?php

declare(strict_types=1);

namespace App\Domain\Sms\QuietHours;

use App\Domain\Routing\Hours\HoursContract;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-16 — the quiet-hours window, evaluated in the TENANT's own timezone
 * (`tenants.timezone_name`) from `sms.quiet_hours` {start, end}; an
 * overnight window (start > end, the 21:00→08:00 default) spans midnight.
 * Every method takes the instant as an INPUT (EC-05's injected clock; the
 * DB clock is the caller's source in the live path).
 *
 * When `sms.quiet_hours.calendar_id` is set, quiet = the CLOSED hours of
 * Doc 12's `HoursContract` — CONSUMED, never re-implemented (`isOpen` /
 * `nextOpen` on the tenant-default calendar, the only door the contract
 * exposes; a configured id that is not the tenant default is LOGGED — a
 * by-id door on the contract would be Doc 12's to add, stated plainly).
 *
 * EC-05 lives in `releaseInstant()`'s CALLER: the sweep recomputes against
 * the setting AT RELEASE — a shrunk window releases early-but-in-window,
 * never mid-night; a grown window holds longer.
 */
final class QuietHoursWindow
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantContext $context,
    ) {}

    public function inQuietHours(DateTimeImmutable $at): bool
    {
        if ($this->calendarLinked()) {
            return ! app(HoursContract::class)->isOpen(null, $at);
        }
        $window = $this->window();
        if ($window === null) {
            return false;
        }
        [$start, $end] = $window;
        $local = $at->setTimezone($this->timezone())->format('H:i');

        // an overnight window (21:00→08:00) spans midnight; a same-day window (13:00→15:00) does not
        return $start > $end
            ? ($local >= $start || $local < $end)
            : ($local >= $start && $local < $end);
    }

    /** The next instant OUTSIDE quiet hours at or after `$at` (identity when already outside). */
    public function releaseInstant(DateTimeImmutable $at): DateTimeImmutable
    {
        if (! $this->inQuietHours($at)) {
            return $at;
        }
        if ($this->calendarLinked()) {
            return app(HoursContract::class)->nextOpen(null, $at);
        }
        $window = $this->window();
        if ($window === null) {
            return $at;
        }
        [$start, $end] = $window;
        $local = $at->setTimezone($this->timezone());
        $release = $local->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2));
        if ($release <= $local) {
            $release = $release->modify('+1 day'); // inside an overnight window's evening half: the end is tomorrow
        }

        return $release->setTimezone(new DateTimeZone('UTC'));
    }

    private function calendarLinked(): bool
    {
        $calendarId = $this->settings->get('sms.quiet_hours.calendar_id');
        if (! is_string($calendarId) || $calendarId === '') {
            return false;
        }
        $default = DB::table('business_calendars')->where('is_tenant_default', true)->value('id');
        if ($default !== $calendarId) {
            Log::warning('sms quiet hours: the configured calendar is not the tenant default — HoursContract exposes the default only (a by-id door is Doc 12\'s)', ['configured' => $calendarId, 'metric' => 'sms.quiet_hours.calendar_mismatch']);
        }

        return true;
    }

    /** @return array{0: string, 1: string}|null [start, end] as HH:MM */
    private function window(): ?array
    {
        $configured = $this->settings->get('sms.quiet_hours');
        if (! is_array($configured)) {
            return null;
        }
        $start = $configured['start'] ?? null;
        $end = $configured['end'] ?? null;
        if (! is_string($start) || ! is_string($end) || preg_match('/^\d{2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}$/', $end) !== 1 || $start === $end) {
            return null; // a shapeless or zero-width window quiets nothing
        }

        return [$start, $end];
    }

    private function timezone(): DateTimeZone
    {
        $name = DB::table('tenants')->where('id', $this->context->tenantId())->value('timezone_name');

        try {
            return new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
