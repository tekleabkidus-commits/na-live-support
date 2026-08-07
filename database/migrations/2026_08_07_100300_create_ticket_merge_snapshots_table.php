<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unmerge snapshots (Doc 06 T-04/FR-20/FS-04/SEC-02) — the pre-merge state, per merge
 * LINK, enabling the 24 h byte-identical reversal (AC-04: restore diff = ∅).
 *
 * - One row per (source → target) link per merge; EC-03's chains unmerge per-link
 *   within each link's own 24 h, so the window anchors on THIS row's `created_at`.
 * - `created_at` IS decision-bearing (it is the 24 h window's authority, a DB-time
 *   comparison) → full R-19b treatment: clock_timestamp() default AND the app role
 *   column-revoked from INSERT and UPDATE on it.
 * - IMMUTABLE (SEC-02): no app UPDATE at all. No consumption flag exists or is needed —
 *   unmerge authority = snapshot present AND within window AND the link still merged
 *   (`tickets.merged_into` set); after an unmerge the pointer is cleared, so a second
 *   attempt refuses naturally. FS-04: once pruned, the refusal states the window
 *   passed; both attempts audited.
 * - PRUNING runs on the platform connection (T-04 "pruned after the window") — the app
 *   role has no DELETE, the retention precedent exactly.
 */
return new class extends Migration
{
    /** Every column EXCEPT created_at — the R-19a column-list form. */
    private const APP_INSERTABLE_COLUMNS = [
        'id', 'tenant_id', 'source_ticket_id', 'target_ticket_id', 'snapshot',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ticket_merge_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('source_ticket_id');
            $table->uuid('target_ticket_id');
            $table->jsonb('snapshot');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()')); // R-19b

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('source_ticket_id')->references('id')->on('tickets');
            $table->foreign('target_ticket_id')->references('id')->on('tickets');

            $table->index(['source_ticket_id', 'created_at']);
        });

        // SEC-02 + T-04: insert-only for the app; pruning is the platform role's.
        DB::statement('REVOKE UPDATE ON ticket_merge_snapshots FROM app');
        DB::statement('REVOKE DELETE ON ticket_merge_snapshots FROM app');

        // R-19b: the app cannot NAME created_at on insert either.
        $columns = implode(', ', self::APP_INSERTABLE_COLUMNS);
        DB::statement('REVOKE INSERT ON ticket_merge_snapshots FROM app');
        DB::statement("GRANT INSERT ({$columns}) ON ticket_merge_snapshots TO app");

        DB::statement('ALTER TABLE ticket_merge_snapshots ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ticket_merge_snapshots FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ticket_merge_snapshots
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_merge_snapshots');
    }
};
