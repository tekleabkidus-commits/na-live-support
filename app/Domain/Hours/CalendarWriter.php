<?php

declare(strict_types=1);

namespace App\Domain\Hours;

use App\Domain\Sla\Clock\CalendarSnapshot;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-12 / EC-05 / EC-10 / NFR-06 — the ONE door for business calendars.
 *
 * - The stored `weekdays` jsonb IS the CalendarSnapshot contract shape — validated
 *   here by the SAME parser the SLA clock reads (CalendarSnapshot::fromArray), so
 *   nothing storable is unreadable and nothing readable is unstorable.
 * - Changes are PROSPECTIVE-ONLY by physics: armed instances embed their snapshot
 *   (BR-03; sla_instances holds no UPDATE grant) — this writer never touches them,
 *   and the edit screen states both facts (EC-05's banner is UI copy over this law).
 * - EC-10: a timezone change recomputes `schedule_next_run_at` on schedule-triggered
 *   rules (the scheduler consumes it from step 4; the recompute clears the stamp so
 *   the next scheduler pass re-derives it in the new timezone — never a stale fire).
 * - Doors: writes ride `sla.policies.edit` at the controller (the document couples
 *   calendars to SLA §4.2/§4.3 and names no calendar key; the catalog is closed).
 */
final class CalendarWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int|string, list<array{open: string, close: string}>>  $weekdays
     */
    public function create(string $name, string $timezone, array $weekdays, string $actorId, bool $isTenantDefault = false, ?string $departmentId = null): string
    {
        $this->assertShape($timezone, $weekdays);
        $tenantId = $this->context->tenantId();
        $calendarId = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $calendarId, $name, $timezone, $weekdays, $isTenantDefault, $departmentId, $actorId): void {
            DB::table('business_calendars')->insert([
                'id' => $calendarId, 'tenant_id' => $tenantId, 'name' => $name,
                'timezone' => $timezone,
                'weekdays' => json_encode($weekdays, JSON_THROW_ON_ERROR),
                'is_tenant_default' => $isTenantDefault, 'department_id' => $departmentId,
            ]);
            $this->announce($calendarId, 'created', $actorId);
        });

        return $calendarId;
    }

    /** @param  array<string, mixed>  $changes  name|timezone|weekdays|is_tenant_default|department_id|active */
    public function update(string $calendarId, array $changes, string $actorId): void
    {
        $calendar = $this->row($calendarId);
        $timezone = is_string($changes['timezone'] ?? null) ? $changes['timezone'] : $calendar->timezone;
        /** @var array<int|string, list<array{open: string, close: string}>> $weekdays */
        $weekdays = is_array($changes['weekdays'] ?? null)
            ? $changes['weekdays']
            : json_decode((string) $calendar->weekdays, true, 8, JSON_THROW_ON_ERROR);
        $this->assertShape($timezone, $weekdays);

        $update = array_intersect_key($changes, array_flip(['name', 'timezone', 'is_tenant_default', 'department_id', 'active']));
        if (array_key_exists('weekdays', $changes)) {
            $update['weekdays'] = json_encode($changes['weekdays'], JSON_THROW_ON_ERROR);
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');

        DB::transaction(function () use ($calendarId, $calendar, $update, $changes, $actorId): void {
            DB::table('business_calendars')->where('id', $calendarId)->update($update);
            $kind = ($changes['active'] ?? true) === false ? 'deactivated' : 'updated';
            $this->announce($calendarId, $kind, $actorId);
            if (isset($changes['timezone']) && $changes['timezone'] !== $calendar->timezone) {
                // EC-10: schedules recompute on timezone change — clearing the stamp
                // makes the next scheduler pass re-derive next-run in the new zone.
                DB::table('automation_rules')->where('trigger', 'schedule')->update(['schedule_next_run_at' => null, 'updated_at' => DB::raw('clock_timestamp()')]);
            }
        });
    }

    public function addHoliday(string $calendarId, string $date, string $name, bool $recurringYearly, string $actorId): string
    {
        $this->row($calendarId);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('holiday date must be YYYY-MM-DD');
        }
        $holidayId = (string) Str::uuid7();
        DB::transaction(function () use ($holidayId, $calendarId, $date, $name, $recurringYearly, $actorId): void {
            DB::table('business_calendar_holidays')->insert([
                'id' => $holidayId, 'tenant_id' => $this->context->tenantId(),
                'calendar_id' => $calendarId, 'date' => $date, 'name' => $name,
                'recurring_yearly' => $recurringYearly,
            ]);
            $this->announce($calendarId, 'holiday_added', $actorId);
        });

        return $holidayId;
    }

    public function removeHoliday(string $holidayId, string $actorId): void
    {
        /** @var object{calendar_id: string}|null $holiday */
        $holiday = DB::table('business_calendar_holidays')->where('id', $holidayId)->first();
        if ($holiday === null) {
            throw new InvalidArgumentException('unknown holiday');
        }
        DB::transaction(function () use ($holidayId, $holiday, $actorId): void {
            // The amendment's one deliberate DELETE: prospective-only list content —
            // armed instances hold snapshot calendars (EC-05), so nothing running moves.
            DB::table('business_calendar_holidays')->where('id', $holidayId)->delete();
            $this->announce($holiday->calendar_id, 'holiday_removed', $actorId);
        });
    }

    /**
     * The resolved snapshot this calendar arms into instances (T-05): weekdays +
     * MATERIALIZED holidays. One shape from editor to armed jsonb.
     *
     * @return array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}
     */
    public function snapshot(string $calendarId): array
    {
        $calendar = $this->row($calendarId);
        /** @var array<int|string, list<array{open: string, close: string}>> $weekdays */
        $weekdays = json_decode((string) $calendar->weekdays, true, 8, JSON_THROW_ON_ERROR);
        /** @var list<object{date: string, recurring_yearly: bool}> $rows */
        $rows = DB::select(
            'SELECT date, recurring_yearly FROM business_calendar_holidays WHERE calendar_id = ? ORDER BY date',
            [$calendarId],
        );
        $holidays = [];
        foreach ($rows as $h) {
            $holidays[] = ['date' => $h->date, 'recurring' => $h->recurring_yearly];
        }

        return ['timezone' => (string) $calendar->timezone, 'weekdays' => $weekdays, 'holidays' => $holidays];
    }

    /** @param  array<int|string, list<array{open: string, close: string}>>  $weekdays */
    private function assertShape(string $timezone, array $weekdays): void
    {
        // The clock's own parser is the validator — one implementation (AC-04's law).
        CalendarSnapshot::fromArray(['timezone' => $timezone, 'weekdays' => $weekdays, 'holidays' => []]);
    }

    private function announce(string $calendarId, string $kind, string $actorId): void
    {
        $tenantId = $this->context->tenantId();
        $this->events->publish('hours.calendar.updated', ['tenant_id' => $tenantId, 'calendar_id' => $calendarId, 'kind' => $kind]);
        $this->audit->record('hours.calendar.updated', 'calendar', $calendarId, AuditActor::Staff, ['calendar_id' => $calendarId, 'kind' => $kind], actorId: $actorId);
    }

    /** @return object{id: string, timezone: string, weekdays: string} */
    private function row(string $calendarId): object
    {
        /** @var object{id: string, timezone: string, weekdays: string}|null $calendar */
        $calendar = DB::table('business_calendars')->where('id', $calendarId)->first();
        if ($calendar === null) {
            throw new InvalidArgumentException('unknown calendar');
        }

        return $calendar;
    }
}
