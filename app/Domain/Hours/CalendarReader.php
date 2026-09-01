<?php

declare(strict_types=1);

namespace App\Domain\Hours;

use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-13 — the calendar RESOLUTION the consumers share: department override
 * (FR-12 / Doc 11 FR-01's ref made readable) > tenant default > none. Answers the
 * resolved snapshot array (the T-05 contract shape) or null when the tenant has
 * painted nothing — "no calendar" means always-open to every consumer (the honest
 * default the A-53 seam documented).
 */
final class CalendarReader
{
    public function __construct(private readonly CalendarWriter $writer) {}

    /**
     * @return array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null
     */
    public function resolvedFor(?string $departmentId): ?array
    {
        if ($departmentId !== null) {
            $override = DB::table('business_calendars')
                ->where('department_id', $departmentId)->where('active', true)->value('id');
            if (is_string($override)) {
                return $this->writer->snapshot($override);
            }
        }
        $default = DB::table('business_calendars')
            ->where('is_tenant_default', true)->where('active', true)->value('id');

        return is_string($default) ? $this->writer->snapshot($default) : null;
    }
}
