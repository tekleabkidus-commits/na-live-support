<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 5, ADDITION 1 (P-C1, countersigned 2026-08-30; filed to ANSWERS before this
 * file exists — the B1-split law): `rescue_flags` — Doc 11 FR-16 a/c, AC-06; Doc 07 FR-05's
 * Rescue population for the two conditions that carry no column (heartbeat-lost, orphaned).
 * The `unreachable` condition stays Doc 05's `tickets.channel_unreachable`.
 *
 * Grant physics (ADR-0003 / R-19b): INSERT (id, tenant_id, ticket_id, reason) + UPDATE
 * (cleared_at, auto_returned) only; NO DELETE; `flagged_at` app-UNWRITABLE on both doors
 * (ordering-relevant: the Rescue sort); FORCED RLS + tenant_isolation; ONE live flag per
 * ticket (partial unique). Not a hot table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement(<<<'SQL'
            CREATE TABLE rescue_flags (
                id            uuid        NOT NULL PRIMARY KEY,
                tenant_id     uuid        NOT NULL REFERENCES tenants (id),
                ticket_id     uuid        NOT NULL REFERENCES tickets (id),
                reason        text        NOT NULL,
                flagged_at    timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                cleared_at    timestamptz(6),
                auto_returned boolean     NOT NULL DEFAULT false,
                created_at    timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT rescue_flags_reason_check CHECK (reason IN ('heartbeat_lost', 'orphaned'))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX rescue_flags_live_unique ON rescue_flags (tenant_id, ticket_id) WHERE cleared_at IS NULL');
        DB::statement('CREATE INDEX rescue_flags_live_sort ON rescue_flags (tenant_id, flagged_at) WHERE cleared_at IS NULL');

        DB::statement('ALTER TABLE rescue_flags ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE rescue_flags FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON rescue_flags
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
        SQL);
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON rescue_flags FROM app');
        DB::statement('GRANT SELECT ON rescue_flags TO app');
        DB::statement('GRANT INSERT (id, tenant_id, ticket_id, reason) ON rescue_flags TO app');
        DB::statement('GRANT UPDATE (cleared_at, auto_returned) ON rescue_flags TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS rescue_flags');
    }
};
