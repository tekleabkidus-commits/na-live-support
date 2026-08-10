<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent status — CURRENT STATE ONLY (Doc 07 FR-28): Online = routable
 * (`agent.status_changed` emitted for Doc 11), Away = keeps assignments / not
 * routable (auto-set after inbox.away.idle_minutes with the cancelable
 * countdown), Offline = manual or logout; heartbeat (redis-volatile) loss
 * > 90 s → system-away. Supervisor force-status
 * (supervision.agent_status.force) reflects with attribution ("set by …").
 *
 * DELIBERATELY NOT A HISTORY TABLE (BR-06: status is routing truth, not
 * surveillance — active-time metrics derive from activity events, Doc 15's;
 * transitions live on the bus + audit, not in rows here).
 *
 * R-19b (approved plan §6): `since` is displayed with attribution and is NOT
 * metric-bearing (BR-06) — written by the transition UPDATE's own
 * clock_timestamp(), app-writable by that discipline; `status`/`set_by_user_id`
 * are state words + attribution; created_at hygiene default only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('agent_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');

            $table->text('status')->default('offline');
            $table->text('reason')->nullable();          // manual|auto_idle|heartbeat_lost|forced|logout
            $table->uuid('set_by_user_id')->nullable();  // attribution for forced status
            $table->timestampTz('since', 6)->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');

            $table->unique(['tenant_id', 'user_id']);
        });

        DB::statement("ALTER TABLE agent_statuses ADD CONSTRAINT agent_statuses_status_check CHECK (status IN ('online', 'away', 'offline'))");
        DB::statement("ALTER TABLE agent_statuses ADD CONSTRAINT agent_statuses_reason_check CHECK (reason IN ('manual', 'auto_idle', 'heartbeat_lost', 'forced', 'logout'))");

        DB::statement('ALTER TABLE agent_statuses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_statuses FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON agent_statuses
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE DELETE ON agent_statuses FROM app');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_statuses');
    }
};
