<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI handoff packets (Doc 06 FR-11/T-05/SEC-02) — the immutable snapshot pinned to a
 * ticket at AI handoff, rendered by Doc 07, produced by Doc 09.
 *
 * - `ticket_id` is UNIQUE (T-05): one packet per ticket, ever.
 * - IMMUTABILITY IS GRANT PHYSICS, not application politeness (SEC-02/AC-08): the app
 *   role has no UPDATE and no DELETE on this table at all. Insert-only. A "corrected"
 *   packet is a new ticket's packet, never an edit.
 * - `payload` carries FR-11's normative contract (summary, intent+confidence, language,
 *   sentiment, urgency, suggested routing + reasons, form_data?, kb_shown[],
 *   transcript_anchor); `schema_version` versions that contract (NFR-06). Validation
 *   lives in createFromHandoff (step 3) — jsonb here, because the contract will grow
 *   by version, not by migration.
 * - `created_at` is DB time for consistency but is NOT ordering/cursor/metric-bearing
 *   (the packet is fetched by ticket, never listed by time), so per R-19b's own scope
 *   sentence it carries no column grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ticket_handoff_packets', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('ticket_id')->unique(); // T-05: one packet per ticket
            $table->jsonb('payload');
            $table->integer('schema_version');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('ticket_id')->references('id')->on('tickets');
        });

        // SEC-02: insert-only, by grants.
        DB::statement('REVOKE UPDATE ON ticket_handoff_packets FROM app');
        DB::statement('REVOKE DELETE ON ticket_handoff_packets FROM app');

        DB::statement('ALTER TABLE ticket_handoff_packets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ticket_handoff_packets FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ticket_handoff_packets
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_handoff_packets');
    }
};
