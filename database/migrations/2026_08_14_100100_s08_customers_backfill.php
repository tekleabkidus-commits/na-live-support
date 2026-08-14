<?php

declare(strict_types=1);

use App\Domain\Customers\Identity\LegacyIdentityBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S08 SCHEMA AMENDMENT 2 — the BACKFILL release (C-12 middle step).
 *
 * Per tenant, under that tenant's own RLS binding (FORCE polices the migrator
 * too — by design): LegacyIdentityBackfill types the channel identities via
 * the migration-local map (unmapped channel = loud refusal), mirrors
 * customers.username into telegram identity metadata, creates phone
 * identities under the KEEPER POLICY (oldest created_at, lowest-UUIDv7
 * tiebreak) with a customer_identity_conflicts row per loser (the permanent
 * home), and derives created_source. The class — not this migration — carries
 * the logic so IdentityBackfillTest drives it directly; this file only
 * iterates tenants and prints the shared-phone report.
 *
 * ENFORCE (NOT NULL on type/value/created_source + straggler re-backfill +
 * ADR 0014 re-normalization) is DELIBERATELY ABSENT here: the S20 resolver
 * still writes legacy-shaped rows until step 2 rewires it. Enforce rides
 * step 2's commit, after the writer changes — the correct expand-contract
 * order (amendment item 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = $this->getConnection() ?? config('database.default');

        // Enumerating tenants is a PLATFORM capability (tenants is FORCE-RLS'd
        // and the migrator holds no bypass — ADR 0003's design working). The
        // per-tenant WRITES then run on the migration connection under each
        // tenant's own binding.
        $tenants = DB::connection('pgsql_platform')->table('tenants')->pluck('id');
        $backfill = new LegacyIdentityBackfill;

        foreach ($tenants as $tenantId) {
            DB::connection($connection)->statement(
                "SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]
            );
            $backfill->run($connection);
        }

        // The shared-phone report — FR-09's "humans consolidate" applied to
        // legacy data. The DURABLE report is the conflicts table itself
        // (undeletable by grant); this print is the operator-facing copy.
        DB::connection($connection)->statement(
            "SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)"
        );
        $count = 0;
        foreach ($tenants as $tenantId) {
            DB::connection($connection)->statement(
                "SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]
            );
            $rows = DB::connection($connection)
                ->table('customer_identity_conflicts')
                ->whereNull('resolved_at')
                ->get(['type', 'keeper_customer_id', 'loser_customer_id']);
            foreach ($rows as $row) {
                $count++;
                // Ids only — the VALUE is PII and stays in the table (ruling 2).
                echo "  [shared-{$row->type}] keeper {$row->keeper_customer_id} / loser {$row->loser_customer_id} — consolidate by hand (FR-09/FR-10)\n";
            }
        }
        echo "S08 backfill: {$count} unconsolidated shared-identity pair(s) recorded in customer_identity_conflicts.\n";
    }

    public function down(): void
    {
        // The backfill is additive and idempotent; the expand migration's
        // down() removes the columns/tables it filled. Nothing to reverse
        // here without destroying the conflict rows the amendment protects.
    }
};
