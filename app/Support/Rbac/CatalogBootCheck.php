<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * T-01's boot-time assertion: DB grants ⊆ catalog (Doc 03 T-01, FS-03).
 *
 * After a bad deploy the DB may hold permission keys the running code's
 * catalog no longer knows (orphans), or the catalog may hold keys no role
 * grants yet (normal). The contract, in both directions, is FAIL SAFE:
 *
 *  - orphan DB keys are LOGGED AND IGNORED, never enforced — the resolver
 *    additionally intersects every role's set with the catalog, so even if
 *    this check never ran, an orphan cannot grant anything;
 *  - a catalog key granted nowhere simply resolves to deny (FR-05's
 *    implicit deny).
 *
 * The check itself must never take the platform down: no table yet (fresh
 * migration), no DB (console contexts) → skip silently. It reports, the
 * resolver defends. Runs once per process from TenancyServiceProvider::boot —
 * under Octane that is genuine boot time, not per-request work.
 */
final class CatalogBootCheck
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    /**
     * @return list<string> the orphan keys found (empty = healthy), for tests
     */
    public function run(): array
    {
        try {
            return $this->scan();
        } finally {
            // The platform connection exists for these two SELECTs only. Under
            // Octane every worker boots this check; leaving the connection open
            // would park one idle BYPASSRLS session PER WORKER on postgres —
            // found empirically: 43 workers exhausted the 100-connection slot
            // budget and took the whole test run down. Close it, always.
            DB::disconnect('pgsql_platform');
        }
    }

    /**
     * @return list<string>
     */
    private function scan(): array
    {
        try {
            if (! Schema::hasTable('roles')) {
                return [];
            }

            // Raw aggregate across tenants, on purpose: this is a platform
            // health check of the SCHEMA's content shape, not tenant data
            // access — it reads permission KEY strings only. The platform
            // connection is the one sanctioned cross-tenant reader; FORCE RLS
            // deliberately polices even the schema owner (ADR 0003), so the
            // app and migrator connections cannot see across tenants at all.
            /** @var list<object{key: string}> $roleKeys */
            $roleKeys = DB::connection('pgsql_platform')->select(
                'SELECT DISTINCT jsonb_array_elements_text(permissions) AS key FROM roles WHERE deleted_at IS NULL',
            );
            /** @var list<object{key: string}> $overrideKeys */
            $overrideKeys = DB::connection('pgsql_platform')->select(
                'SELECT DISTINCT permission AS key FROM user_permission_overrides WHERE revoked_at IS NULL',
            );
        } catch (Throwable $e) {
            // FS-01 posture: a health check that cannot run must not become an
            // outage — but it must SAY so, or a broken check reads as healthy
            // (this very line caught a real defect on its first run: the
            // silent version masked the migrator connection being policed).
            Log::warning('rbac.catalog.check_unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        $orphans = [];
        foreach ([...$roleKeys, ...$overrideKeys] as $row) {
            if (! $this->catalog->has($row->key)) {
                $orphans[$row->key] = true;
            }
        }
        $orphans = array_keys($orphans);

        if ($orphans !== []) {
            Log::warning('rbac.catalog.orphan_keys', [
                // Keys only — identifiers, no tenant data (C-14 logs carry
                // context; these are catalog strings, not PII).
                'orphan_keys' => $orphans,
                'action' => 'ignored — orphans are never enforced (FS-03); deploy/catalog drift needs attention',
            ]);
        }

        return $orphans;
    }
}
