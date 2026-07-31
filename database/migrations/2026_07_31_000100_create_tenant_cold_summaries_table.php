<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-05's cold-summary ledger (Doc 01 BR-05).
 *
 * "Suspended tenants accrue inbound data ≤ 30 days; beyond that, inbound is still
 * ACKed to channels but stored in cold summary form (count + last message pointer)."
 *
 * One row per (tenant, channel): a count and the pointer to the last message, which
 * is exactly the spec's phrase and nothing more. No message body, no sender, no
 * timestamps per message — the entire point is bounded liability for a dead account,
 * and a summary that grows per message is not a summary.
 *
 * Doc 04's ingestion writes this through ColdSummaryStore when InboundAccrualPolicy
 * says cold; nothing else writes it. RLS enabled here, in the creating migration —
 * the S1 RLS migration polices the tables that existed then, each later table
 * carries its own protection from birth.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The first table born AFTER the RLS migration: adding its tenants FK runs a
        // validation scan whose join touches `tenants`, whose FORCE RLS policy calls
        // current_setting('app.tenant_id') — which ERRORS when the parameter has never
        // been set on this connection. The value is irrelevant (the new table is
        // empty, so the scan matches nothing); the parameter must merely EXIST.
        // Transaction-local, so nothing leaks past this migration. Every future
        // tenant-FK table needs this line or its equivalent.
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('tenant_cold_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();                       // C-01 UUIDv7
            $table->uuid('tenant_id');
            $table->string('channel', 32);                       // adapter key, e.g. "telegram"
            $table->bigInteger('message_count')->default(0);
            $table->string('last_message_pointer')->nullable();  // opaque channel-side reference
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'channel']);
        });

        DB::statement('ALTER TABLE tenant_cold_summaries ADD CONSTRAINT tenant_cold_summaries_count_check CHECK (message_count >= 0)');

        // BR-02: default privileges include DELETE; every tenant-scoped table revokes
        // it at birth. Caught by GrantPhysicsTest on this migration's first audit —
        // the summary row for a dead account is itself never hard-deleted by the app.
        DB::statement('REVOKE DELETE ON tenant_cold_summaries FROM app');

        DB::statement('ALTER TABLE tenant_cold_summaries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenant_cold_summaries FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON tenant_cold_summaries
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_cold_summaries');
    }
};
