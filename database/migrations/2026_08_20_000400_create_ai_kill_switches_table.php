<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_kill_switches (SCHEMA AMENDMENT 3 item 3, countersigned; Doc 09 FR-18,
 * US-07, AC-08, SEC-05) — one row per scope-grid combination
 * (surface x channel x endpoint; NULL = "all" at that axis), singular by
 * UNIQUE NULLS NOT DISTINCT. This unique index IS the writer's upsert
 * arbiter and stays the only non-PK unique (the arbiter law).
 *
 * flipped_at is PINNED-PROVENANCE (the S08 blocked_at idiom): a byte-copy of
 * the flip's own audit-entry DB stamp, never a fresh clock read — AC-08's
 * "OFF since {time} by {actor}" caption must agree with the audit trail to
 * the byte. The writer's inverted-R-19 byte-equality control is SABOTAGE #16
 * of the amended enumeration (floor 16), executing when the writer lands
 * (step 8). App-writable by that documented discipline.
 *
 * History lives in the audit log (SEC-05 high-visibility, actor + old/new);
 * this table stores current state + its provenance stamps. REVOKE DELETE —
 * a switch row is its scope's anchor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ai_kill_switches', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->text('surface');
            $table->text('channel')->nullable();
            $table->text('endpoint_ref')->nullable();
            $table->boolean('is_off')->default(false);
            $table->text('reason')->nullable();
            $table->uuid('flipped_by')->nullable();
            $table->timestampTz('flipped_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_kill_switches
                ADD CONSTRAINT ai_kill_switches_surface_check CHECK (surface IN ('chatbot', 'copilot', 'supervisor')),
                ADD CONSTRAINT ai_kill_switches_channel_check CHECK (channel IS NULL OR channel IN ('telegram', 'web'))
            SQL);

        // PG16's NULLS NOT DISTINCT: the NULL-scoped ("all") rows are singletons too.
        DB::statement('CREATE UNIQUE INDEX ai_kill_switches_scope ON ai_kill_switches (tenant_id, surface, channel, endpoint_ref) NULLS NOT DISTINCT');

        DB::statement('ALTER TABLE ai_kill_switches ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_kill_switches FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ai_kill_switches
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ai_kill_switches FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, surface, channel, endpoint_ref, is_off, reason, flipped_by, flipped_at, updated_at) ON ai_kill_switches TO app');
        DB::statement('GRANT UPDATE (is_off, reason, flipped_by, flipped_at, updated_at) ON ai_kill_switches TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_kill_switches');
    }
};
