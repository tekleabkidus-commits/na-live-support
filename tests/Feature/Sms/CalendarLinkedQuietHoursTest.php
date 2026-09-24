<?php

declare(strict_types=1);

use App\Domain\Hours\CalendarWriter;
use App\Domain\Sms\QuietHours\QuietHoursWindow;
use App\Support\Settings\SettingsRepository;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-16's calendar link — Doc 12's HoursContract CONSUMED, never
| re-implemented: with `sms.quiet_hours.calendar_id` set, quiet = the
| calendar's CLOSED hours (isOpen/nextOpen on the tenant-default calendar,
| the only door the contract exposes); without it, the plain window decides
| (the calendar-less control on the SAME instants). Fixed instants
| throughout (the injected-clock discipline).
*/

it('calendar-linked: quiet = the painted calendar\'s closed hours; releaseInstant = its nextOpen; the calendar-less control answers by the plain window on the same instants', function (): void {
    smsPipeScene();
    $writer = app(CalendarWriter::class);
    $weekdays = [];
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays[$day] = [['open' => '08:00', 'close' => '17:00']];
    }
    $calendarId = $writer->create('QH link', 'Africa/Addis_Ababa', $weekdays, (string) Illuminate\Support\Str::uuid7(), isTenantDefault: true);

    // Wed 2026-09-02: 10:00 Addis (07:00Z) is OPEN → not quiet; 20:00 Addis (17:00Z) is CLOSED → quiet
    $open = new DateTimeImmutable('2026-09-02T07:00:00Z');
    $closed = new DateTimeImmutable('2026-09-02T17:00:00Z');

    // the calendar-less CONTROL first: a window that covers 20:00 Addis but not 10:00
    app(SettingsRepository::class)->set('sms.quiet_hours', ['start' => '18:00', 'end' => '08:00']);
    $window = app(QuietHoursWindow::class);
    expect($window->inQuietHours($open))->toBeFalse()->and($window->inQuietHours($closed))->toBeTrue();
    expect($window->releaseInstant($closed)->format('Y-m-d\TH:i'))->toBe('2026-09-03T05:00', 'the plain window releases at 08:00 Addis = 05:00Z');

    // now LINK the calendar: the same instants answer by the CALENDAR's hours
    app(SettingsRepository::class)->set('sms.quiet_hours.calendar_id', $calendarId);
    expect($window->inQuietHours($open))->toBeFalse()
        ->and($window->inQuietHours($closed))->toBeTrue();
    // Sat 2026-09-05 12:00Z: the plain window would say clear at noon — the CALENDAR says the weekend is closed
    $saturdayNoon = new DateTimeImmutable('2026-09-05T12:00:00Z');
    expect($window->inQuietHours($saturdayNoon))->toBeTrue('the weekend is the calendar\'s closed hours');
    expect($window->releaseInstant($saturdayNoon)->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-07T05:00:00Z', 'releaseInstant = HoursContract::nextOpen — Mon 08:00 Addis');

    // unlink: the control shape returns
    app(SettingsRepository::class)->set('sms.quiet_hours.calendar_id', '');
    expect($window->inQuietHours($saturdayNoon))->toBeFalse('without the link, Saturday noon is not in the 18:00–08:00 window');
});
