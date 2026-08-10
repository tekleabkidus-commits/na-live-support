<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side selection tokens (Doc 07 T-05 / SEC-06): "select all in view"
 * mints a token carrying the view hash, the filter snapshot and the true
 * count; Doc 06's bulk machinery consumes it, resolving ids at EXECUTION time
 * with a drift report. USER-BOUND and SINGLE-USE per bulk execution (SEC-06),
 * capped by Doc 06's bulk limits.
 *
 * R-19b (approved plan §6): `created_at` full treatment — expiry comparisons
 * run in SQL against it (C-11), so it is ordering-bearing and db-time-only.
 * `consumed_at` is the single-use latch: compared for NULL-ness only, written
 * by the consuming UPDATE's own clock_timestamp() — UPDATE is column-granted
 * to exactly that latch, so a token's identity, snapshot and count can never
 * be rewritten after minting.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('inbox_selection_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');            // SEC-06: user-bound

            $table->text('view_hash');
            $table->jsonb('filter_snapshot')->default('{}');
            $table->integer('item_count');

            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');

            $table->index(['tenant_id', 'user_id']);
        });

        DB::statement('ALTER TABLE inbox_selection_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbox_selection_tokens FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON inbox_selection_tokens
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        // Mint-once physics: INSERT column-granted (created_at excluded —
        // defaults fill), UPDATE granted on the consumed_at latch ONLY,
        // DELETE revoked (tokens expire by SQL comparison, never by erasure).
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON inbox_selection_tokens FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, user_id, view_hash, filter_snapshot, item_count) ON inbox_selection_tokens TO app');
        DB::statement('GRANT UPDATE (consumed_at) ON inbox_selection_tokens TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_selection_tokens');
    }
};
