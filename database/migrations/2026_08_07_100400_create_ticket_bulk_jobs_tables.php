<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk operation batches (Doc 06 FR-25/T-06/SEC-04) — a queued batch of INDIVIDUALLY
 * validated operations on the automation queue: per-item permission checks LIVE at
 * execution (EC-12), per-item partial success, no all-or-nothing lock, capped 200 per
 * batch (25 on mobile — a client rule, §9), one `ticket.bulk_completed {ok, failed[]}`
 * summary at the end.
 *
 * Two tables in one migration because an item row without its job is meaningless:
 * - `ticket_bulk_jobs`: the batch — requested action, its params, requester, totals.
 * - `ticket_bulk_job_items`: one row per ticket, `position` preserving submission
 *   order for the progress drawer; `status` pending → ok|failed with a named
 *   `failure_reason` (honest partial results, AC-07).
 *
 * Neither table's timestamps participate in an ordering key, cursor, or reported
 * metric (progress reads the item rows by position; Doc 15 reads ticket events, not
 * this operational table) → clock_timestamp() defaults for consistency, no column
 * grants, per R-19b's scope sentence. DELETE revoked (ADR 0003): a finished batch is
 * an operational record; SEC-03's bulk-summary audit references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ticket_bulk_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('requested_by');
            $table->text('action');
            $table->jsonb('params')->nullable();
            $table->integer('total');
            $table->text('status')->default('queued');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('requested_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE ticket_bulk_jobs ADD CONSTRAINT ticket_bulk_jobs_action_check CHECK (action IN ('assign', 'transfer', 'tag', 'priority', 'resolve', 'close', 'spam'))");
        DB::statement("ALTER TABLE ticket_bulk_jobs ADD CONSTRAINT ticket_bulk_jobs_status_check CHECK (status IN ('queued', 'running', 'completed'))");
        DB::statement('ALTER TABLE ticket_bulk_jobs ADD CONSTRAINT ticket_bulk_jobs_total_check CHECK (total >= 1 AND total <= 200)');

        Schema::create('ticket_bulk_job_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('bulk_job_id');
            $table->uuid('ticket_id');
            $table->integer('position');
            $table->text('status')->default('pending');
            $table->text('failure_reason')->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bulk_job_id')->references('id')->on('ticket_bulk_jobs');
            $table->foreign('ticket_id')->references('id')->on('tickets');

            $table->unique(['bulk_job_id', 'position']);
            $table->index(['bulk_job_id', 'status']);
        });

        DB::statement("ALTER TABLE ticket_bulk_job_items ADD CONSTRAINT ticket_bulk_job_items_status_check CHECK (status IN ('pending', 'ok', 'failed'))");

        DB::statement('REVOKE DELETE ON ticket_bulk_jobs FROM app');
        DB::statement('REVOKE DELETE ON ticket_bulk_job_items FROM app');

        foreach (['ticket_bulk_jobs', 'ticket_bulk_job_items'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tableName} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$tableName}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_bulk_job_items');
        Schema::dropIfExists('ticket_bulk_jobs');
    }
};
