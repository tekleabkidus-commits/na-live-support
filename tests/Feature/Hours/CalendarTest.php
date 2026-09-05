<?php

declare(strict_types=1);

use App\Domain\Hours\CalendarReader;
use App\Domain\Hours\CalendarWriter;
use App\Domain\Routing\Hours\HoursContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-12 / FR-13 / EC-05 / EC-10 / AC-04 + P-A53 (the countersigned
| HoursContract source swap; Doc 11 HoursTest is the byte-untouched PRE control)
|--------------------------------------------------------------------------
|
| - The writer validates through the SLA clock's OWN parser (one shape from
|   editor to armed snapshot — AC-04's one-implementation law).
| - Split shifts, recurring holidays, department overrides, tenant-default
|   uniqueness (physics), the deliberate holiday DELETE.
| - EC-05/BR-03: calendar edits are prospective — an armed instance's embedded
|   snapshot is untouched (grant physics proves the strong half in
|   SlaGrantPhysicsTest; this file proves the writer never tries).
| - EC-10: a timezone change clears schedule_next_run_at so the scheduler
|   re-derives next-run in the new zone.
| - P-A53: with NO calendar painted, HoursContract behaves exactly as the seam
|   (Doc 11 HoursTest runs unchanged elsewhere; the PRE leg here re-asserts the
|   always-open shape); with a painted calendar, the REAL source answers —
|   split-shift and holiday exact.
*/

const ADDIS = 'Africa/Addis_Ababa';

function weekdays95(): array
{
    $w = [];
    foreach ([1, 2, 3, 4, 5] as $d) {
        $w[$d] = [['open' => '08:00', 'close' => '17:00']];
    }

    return $w;
}

it('FR-12: creates, validates through the clock parser, and refuses malformed shapes', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $writer = app(CalendarWriter::class);
    $actor = (string) Str::uuid7();

    $id = $writer->create('Head office', ADDIS, weekdays95(), $actor, isTenantDefault: true);
    $snapshot = $writer->snapshot($id);
    expect($snapshot['timezone'])->toBe(ADDIS)
        ->and($snapshot['weekdays'][1][0]['close'])->toBe('17:00')
        ->and($snapshot['holidays'])->toBe([]);

    foreach ([
        ['tz' => 'Not/AZone', 'weekdays' => weekdays95()],
        ['tz' => ADDIS, 'weekdays' => [8 => [['open' => '08:00', 'close' => '17:00']]]],
        ['tz' => ADDIS, 'weekdays' => [1 => [['open' => '8:00', 'close' => '17:00']]]],
        ['tz' => ADDIS, 'weekdays' => [1 => [['open' => '17:00', 'close' => '08:00']]]],
    ] as $bad) {
        expect(fn () => $writer->create('Bad', $bad['tz'], $bad['weekdays'], $actor))
            ->toThrow(Exception::class);
    }

    // NFR-06: the write is evented AND audited.
    expect(DB::table('outbox')->where('name', 'hours.calendar.updated')->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_log')->where('action_key', 'hours.calendar.updated')->count())->toBeGreaterThan(0);
});

it('FR-12 physics: one tenant default, split shifts stored intact, holidays add/remove with the deliberate DELETE', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $writer = app(CalendarWriter::class);
    $actor = (string) Str::uuid7();

    $writer->create('Default', ADDIS, weekdays95(), $actor, isTenantDefault: true);
    try {
        DB::transaction(fn () => $writer->create('Second default', ADDIS, weekdays95(), $actor, isTenantDefault: true));
        $this->fail('a second tenant-default calendar landed');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('business_calendars_tenant_default');
    }

    $split = $writer->create('Split shift', 'UTC', [1 => [['open' => '08:00', 'close' => '12:00'], ['open' => '14:00', 'close' => '18:00']]], $actor);
    expect($writer->snapshot($split)['weekdays'][1])->toHaveCount(2);

    $holiday = $writer->addHoliday($split, '2026-09-11', 'Enkutatash', true, $actor);
    expect($writer->snapshot($split)['holidays'])->toBe([['date' => '2026-09-11', 'recurring' => true]]);
    $writer->removeHoliday($holiday, $actor);
    expect($writer->snapshot($split)['holidays'])->toBe([]);
});

it('EC-05/BR-03: a calendar edit is prospective — an armed instance keeps its embedded snapshot byte-identically', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $writer = app(CalendarWriter::class);
    $actor = (string) Str::uuid7();
    $calendarId = $writer->create('Before', ADDIS, weekdays95(), $actor, isTenantDefault: true);
    $armedSnapshot = json_encode($writer->snapshot($calendarId), JSON_THROW_ON_ERROR);

    $policyId = (string) Str::uuid7();
    DB::table('sla_policies')->insert([
        'id' => $policyId, 'tenant_id' => $tenant->identifier(), 'name' => 'P', 'position' => 1,
        'clock_mode' => 'business_hours', 'calendar_id' => $calendarId,
        'targets' => json_encode(['normal' => ['first_response_s' => 600]]),
    ]);
    $instanceId = (string) Str::uuid7();
    DB::table('sla_instances')->insert([
        'id' => $instanceId, 'tenant_id' => $tenant->identifier(),
        'ticket_id' => $ticket->identifier(), 'policy_id' => $policyId,
        'policy_snapshot' => json_encode(['warning_pct' => 80]),
        'calendar_snapshot' => $armedSnapshot,
    ]);

    $writer->update($calendarId, ['weekdays' => [6 => [['open' => '10:00', 'close' => '14:00']]]], $actor);
    $writer->addHoliday($calendarId, '2027-01-07', 'Genna', true, $actor);

    // jsonb normalizes key order, so equality is structural, not byte (the BYTE
    // half of immutability is grant physics — SlaGrantPhysicsTest's 42501).
    expect(json_decode((string) DB::table('sla_instances')->where('id', $instanceId)->value('calendar_snapshot'), true))
        ->toEqual(json_decode($armedSnapshot, true), 'a calendar edit reached an armed instance — BR-03/EC-05 broken');
});

it('EC-10: a timezone change clears schedule_next_run_at on schedule rules only', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $writer = app(CalendarWriter::class);
    $actor = (string) Str::uuid7();
    $calendarId = $writer->create('TZ', ADDIS, weekdays95(), $actor);

    $scheduleRule = (string) Str::uuid7();
    $eventRule = (string) Str::uuid7();
    DB::table('automation_rules')->insert(['id' => $scheduleRule, 'tenant_id' => $tenant->identifier(), 'name' => 'Nightly', 'trigger' => 'schedule', 'position' => 1, 'schedule_cron' => '0 2 * * *', 'schedule_next_run_at' => DB::raw("clock_timestamp() + interval '1 hour'")]);
    DB::table('automation_rules')->insert(['id' => $eventRule, 'tenant_id' => $tenant->identifier(), 'name' => 'OnCreate', 'trigger' => 'ticket.created', 'position' => 2]);

    $writer->update($calendarId, ['timezone' => 'UTC'], $actor);
    expect(DB::table('automation_rules')->where('id', $scheduleRule)->value('schedule_next_run_at'))->toBeNull()
        ->and(DB::table('automation_rules')->where('id', $eventRule)->value('trigger'))->toBe('ticket.created');

    // A non-timezone edit clears nothing.
    DB::table('automation_rules')->where('id', $scheduleRule)->update(['schedule_next_run_at' => DB::raw('clock_timestamp()')]);
    $writer->update($calendarId, ['name' => 'Renamed'], $actor);
    expect(DB::table('automation_rules')->where('id', $scheduleRule)->value('schedule_next_run_at'))->not->toBeNull();
});

it('P-A53 PRE control: a calendar-less tenant answers the seam shape exactly (always-open in this harness)', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $hours = app(HoursContract::class);

    expect($hours->windowFor(null))->toBe(['open' => '00:00', 'close' => '24:00', 'days' => [1, 2, 3, 4, 5, 6, 7]])
        ->and($hours->isOpen(null, new DateTimeImmutable('2026-09-02T03:00:00Z')))->toBeTrue()
        ->and(app(CalendarReader::class)->resolvedFor(null))->toBeNull();
});

it('P-A53 REAL source: a painted calendar drives windowFor/isOpen/nextOpen/shiftEnd — split-shift and holiday exact', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $writer = app(CalendarWriter::class);
    $actor = (string) Str::uuid7();
    $calendarId = $writer->create('Real', ADDIS, weekdays95(), $actor, isTenantDefault: true);
    $writer->addHoliday($calendarId, '2026-09-11', 'Enkutatash', true, $actor);
    $hours = app(HoursContract::class);

    // P-CAL-WEEKDAY (S13, countersigned 2026-09-05 with ONE condition — the assertion STAYS HERE, branched):
    // `windowFor` projects TODAY (open/close) and the coming week (days) from the DB clock (C-11 — no seam to
    // freeze), so with a Mon–Fri fixture and a recurring Friday holiday BOTH halves are date-sensitive. Branch on
    // the DB clock's Addis weekday: a weekday answers the painted shift; Sat/Sun answer the EXACT closed shape a
    // Mon–Fri calendar returns for a day with no window (open '00:00', close '24:00' — the Saturday finding); and
    // when the projected week [today, today+6] holds the recurring 09-11 Friday, `days` omits 5. The date is
    // read BEFORE the projection so a red names its day. Every fixed-date assertion below is byte-untouched. [flagged]
    $todayAddis = $hours->now()->setTimezone(new DateTimeZone(ADDIS))->setTime(0, 0);
    $isWeekend = (int) $todayAddis->format('N') >= 6;
    $weekHoldsHoliday = false;
    for ($n = 0; $n <= 6; $n++) {
        if ($todayAddis->modify(sprintf('+%d day', $n))->format('m-d') === '09-11') {
            $weekHoldsHoliday = true;
        }
    }
    $window = $hours->windowFor(null);
    expect($window['open'])->toBe($isWeekend ? '00:00' : '08:00', 'projected on '.$todayAddis->format('D Y-m-d'))
        ->and($window['close'])->toBe($isWeekend ? '24:00' : '17:00', 'projected on '.$todayAddis->format('D Y-m-d'))
        ->and($window['days'])->toBe($weekHoldsHoliday ? [1, 2, 3, 4] : [1, 2, 3, 4, 5], 'projected on '.$todayAddis->format('D Y-m-d'));

    // Wed 2026-09-02: 10:00 Addis = 07:00Z open; 18:00 Addis = 15:00Z closed.
    expect($hours->isOpen(null, new DateTimeImmutable('2026-09-02T07:00:00Z')))->toBeTrue()
        ->and($hours->isOpen(null, new DateTimeImmutable('2026-09-02T15:00:00Z')))->toBeFalse()
        // Fri 2026-09-11 is the recurring holiday — closed at what would be 10:00 local.
        ->and($hours->isOpen(null, new DateTimeImmutable('2026-09-11T07:00:00Z')))->toBeFalse();

    // From Sat 2026-09-05 12:00Z the next open is Mon 2026-09-07 08:00 Addis = 05:00Z.
    expect($hours->nextOpen(null, new DateTimeImmutable('2026-09-05T12:00:00Z'))->format('Y-m-d\TH:i:s\Z'))
        ->toBe('2026-09-07T05:00:00Z');
    // From Thu 2026-09-10 18:00 Addis (15:00Z, after close) the next open SKIPS the
    // Friday holiday and the weekend: Mon 2026-09-14 05:00Z.
    expect($hours->nextOpen(null, new DateTimeImmutable('2026-09-10T15:00:00Z'))->format('Y-m-d\TH:i:s\Z'))
        ->toBe('2026-09-14T05:00:00Z');
});

it('P-CAL-WEEKDAY: a calendar painted on all seven days, no holiday, projects its window on ANY day the DB clock lands on — weekday-independent by construction', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $writer = app(CalendarWriter::class);
    $actor = (string) Str::uuid7();
    $allWeek = [];
    for ($d = 1; $d <= 7; $d++) {
        $allWeek[$d] = [['open' => '08:00', 'close' => '17:00']];
    }
    $writer->create('Every day', ADDIS, $allWeek, $actor, isTenantDefault: true);
    $hours = app(HoursContract::class);

    $window = $hours->windowFor(null);
    expect($window['open'])->toBe('08:00')
        ->and($window['close'])->toBe('17:00')
        ->and($window['days'])->toBe([1, 2, 3, 4, 5, 6, 7]);
    // the day the projection used is the DB's, in the calendar's zone — recorded so a red names its day
    $today = (int) $hours->now()->setTimezone(new DateTimeZone(ADDIS))->format('N');
    expect($today)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(7);
});
