<?php

declare(strict_types=1);

namespace App\Domain\Routing\Intake;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-08 step 1's terminal fallback — "tenant default department" (A-55, filed at
 * S11 step 5): the seeded "General" department (slug `general`, position 0 — FR-01's first
 * name; OrganizationSeeder), else the lowest-positioned live, active department. No
 * setting key: the document names no setting and the seed guarantees the row from
 * provisioning (STOP-C.1). A tenant with NO department at all is FS-04's parked state.
 */
final class TenantDefaultDepartment
{
    /** @return array{department_id: string, queue_id: string}|null */
    public function resolve(): ?array
    {
        /** @var object{id: string, default_queue_id: ?string}|null $row */
        $row = DB::table('departments')->whereNull('deleted_at')->where('is_active', true)
            ->orderByRaw("CASE WHEN slug = 'general' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->first(['id', 'default_queue_id']);
        if ($row === null || $row->default_queue_id === null) {
            return null;
        }

        return ['department_id' => $row->id, 'queue_id' => $row->default_queue_id];
    }
}
