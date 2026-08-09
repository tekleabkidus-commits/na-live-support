<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flow-target indirection table (Doc 05 SEC-05, FR-14/FR-26/FR-27).
 *
 * THE security property: callback data and deep-link payloads are UNTRUSTED
 * INPUT and are never executed as flow ids. A menu button or validated
 * payload resolves through THIS table — (tenant, bot, key) → typed target —
 * so the only flows reachable are ones an operator registered. Unknown keys
 * resolve to nothing and are ignored gracefully (FR-26).
 *
 * - `target_type` is the FR-27 closed set: KB flow / Form / AI free-chat /
 *   Human handoff / URL. Execution of the first three is Docs 10/12/09's
 *   (pre-agreed bucket); the designer, this indirection, and callback routing
 *   are Doc 05's.
 * - DELETE stays GRANTED as a DECISION: removing a menu button removes its
 *   target row. Asserted explicitly in TelegramGrantPhysicsTest.
 * - Timestamps are bookkeeping (no ordering/cursor/metric — plan's R-19b
 *   analysis): DB-time default for hygiene, grants untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_flow_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('bot_id');
            $table->text('key');                // slug carried in callback data
            $table->text('target_type');
            $table->text('target_ref')->nullable(); // KB doc id / form id / URL

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bot_id')->references('id')->on('telegram_bots');

            $table->unique(['tenant_id', 'bot_id', 'key']);
        });

        DB::statement("ALTER TABLE telegram_flow_targets ADD CONSTRAINT telegram_flow_targets_type_check CHECK (target_type IN ('kb_flow', 'form', 'ai_chat', 'human_handoff', 'url'))");

        DB::statement('ALTER TABLE telegram_flow_targets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_flow_targets FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON telegram_flow_targets
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_flow_targets');
    }
};
