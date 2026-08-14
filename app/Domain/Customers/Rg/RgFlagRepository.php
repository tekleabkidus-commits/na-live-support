<?php

declare(strict_types=1);

namespace App\Domain\Customers\Rg;

use Illuminate\Support\Facades\DB;

/**
 * T-05/NFR-06: THE NO-CACHE PATH. Every RG read in the platform routes
 * through this class, and this class touches the DATABASE on every call —
 * no Cache facade, no memoization, no remember(). AC-B4-01's law ("RG flag
 * reads are never cached") and NFR-06 agree, and both are proven twice: the
 * freshness fixture (set → read → clear → read, fresh both times) goes red
 * the moment anyone wraps these queries in a cache — which is exactly
 * sabotage #2.
 *
 * The OTHER cache in the path — the Doc 03 capability cache deciding WHO may
 * see the flag — already bypasses itself for customers.rg_flag.* (R-04's
 * high-sensitivity list in CachedCapabilityMaps, Doc 03's own code); T-05's
 * bypass-list clause is asserted, not re-implemented (RgVisibilityTest).
 */
final class RgFlagRepository
{
    /** @return object{rg_flag: bool, rg_flag_set_by: ?string, rg_flag_set_at: ?string, rg_flag_reason: ?string}|null */
    public function current(string $customerId): ?object
    {
        /** @var object{rg_flag: bool, rg_flag_set_by: ?string, rg_flag_set_at: ?string, rg_flag_reason: ?string}|null $row */
        $row = DB::selectOne(
            'SELECT rg_flag, rg_flag_set_by, rg_flag_set_at::text AS rg_flag_set_at, rg_flag_reason FROM customers WHERE id = ?',
            [$customerId],
        );

        return $row;
    }

    /**
     * The WHOLE history, oldest first — AC-B4-02's server half: every set and
     * clear with author, reason, source and the AI excerpt reference;
     * insert-only by grant, so an export run twice a year apart still
     * contains the same early rows.
     *
     * @return list<object{id: string, action: string, source: string, actor_id: ?string, reason: string, excerpt_ref: ?string, created_at: string}>
     */
    public function history(string $customerId): array
    {
        /** @var list<object{id: string, action: string, source: string, actor_id: ?string, reason: string, excerpt_ref: ?string, created_at: string}> $rows */
        $rows = DB::select(
            'SELECT id, action, source, actor_id, reason, excerpt_ref, created_at::text AS created_at
               FROM rg_flag_history WHERE customer_id = ? ORDER BY created_at ASC, id ASC',
            [$customerId],
        );

        return $rows;
    }
}
