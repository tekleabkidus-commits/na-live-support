<?php

declare(strict_types=1);

use App\Domain\Customers\Identity\ChannelIdentityMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 2 — ADDITION 8 (filed in ANSWERS; approved by the advisor
 * at the S08 step-8 checkpoint with four conditions): drop the legacy
 * `customer_identities_match_unique (tenant_id, channel, external_user_id)`
 * index. ON CONFLICT with a named arbiter handles conflicts on THAT index
 * only — a race loser hitting the legacy index mid-statement throws raw
 * 23505 and bypasses the graceful RaceLost path (T-02's guarantee breached;
 * captured: docs/evidence/S08-step8/addition8-raw23505-PREDROP-01.txt).
 *
 * SCOPE, STATED EXPLICITLY (condition 4): THIS DROPS THE INDEX ONLY. The
 * deprecated channel / external_user_id COLUMNS, their C-12 contract window,
 * and DeprecatedColumnDropGate are UNTOUCHED — this is NOT the column drop;
 * the columns still leave two releases after deprecation through the gate.
 *
 * SUBSUMPTION IS CHECKED HERE, NOT ASSERTED (condition 1a): the drop REFUSES
 * to run if any existing row's legacy pair diverges from its arbiter pair —
 * the check travels with the migration to every database it ever runs on
 * (including the backfill-written dev rows). Writers always mirror the pairs
 * (resolver + LinkIdentity; the standing mirror-invariant test), so the
 * arbiter subsumes everything the legacy index constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        // channel→type is lawful when it matches ChannelIdentityMap's declared
        // mapping, or channel simply equals type (staff phone/email links).
        $mappedPairs = implode(', ', array_map(
            static fn (string $channel): string => sprintf("('%s', '%s')", $channel, ChannelIdentityMap::typeFor($channel)),
            ChannelIdentityMap::channels(),
        ));

        // Per tenant under that tenant's own RLS binding — the migrator is
        // NOBYPASSRLS and FORCE polices it (the backfill migration's exact
        // pattern; tenants enumerate through the platform connection).
        /** @var list<string> $tenantIds */
        $tenantIds = DB::connection('pgsql_platform')->table('tenants')->pluck('id')->all();
        $divergentTotal = 0;
        foreach ($tenantIds as $tenantId) {
            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);

            /** @var object{n: int|string} $divergent */
            $divergent = DB::selectOne(
                <<<SQL
                SELECT count(*) AS n FROM customer_identities
                WHERE channel IS NOT NULL
                  AND (
                    external_user_id IS DISTINCT FROM value
                    OR NOT (channel = type OR (channel, type) IN ({$mappedPairs}))
                  )
                SQL,
            );
            $divergentTotal += (int) $divergent->n;
        }
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        if ($divergentTotal > 0) {
            throw new RuntimeException(sprintf(
                'ADDITION 8 REFUSED: %d customer_identities row(s) carry a legacy (channel, external_user_id) pair that '
                .'diverges from (type, value) — the arbiter does NOT subsume the legacy index on this database. '
                .'Investigate the rows before dropping (condition 1a is doing its job; do not weaken this check).',
                $divergentTotal,
            ));
        }

        DB::statement('DROP INDEX IF EXISTS customer_identities_match_unique');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX customer_identities_match_unique ON customer_identities (tenant_id, channel, external_user_id)',
        );
    }
};
