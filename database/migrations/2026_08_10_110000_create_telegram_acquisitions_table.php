<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deep-link acquisition metadata (Doc 05 FR-26 / SEC-05; S23 step 12 — the
 * self-audit found FR-26 planned into step 5 but never built).
 *
 * ADAPTER-SIDE BY DESIGN: FR-26 says the payload is "stored on the
 * conversation as acquisition metadata". A column on `conversations` would
 * be a Doc 04 core edit (the zero-core-edits assertion, inherited row 1);
 * this table is the adapter-side equivalent — keyed by the conversation's
 * own identity (bot + external chat), readable per conversation through
 * DeepLinkResolver::forConversation (the Doc 09 first-turn seam).
 * FILED INTERPRETATION, for the advisor's countersign.
 *
 * Physics:
 * - FIRST-WINS, WRITE-ONCE: acquisition answers "where did this player COME
 *   FROM" — a later /start must not rewrite history. UNIQUE + ON CONFLICT
 *   DO NOTHING in the writer; UPDATE and DELETE revoked at the grant level.
 * - R-19b: `created_at` is provenance (when acquired) — DB-default
 *   clock_timestamp(), never insertable (denied by the INSERT column list;
 *   defaults fill — the health-columns idiom).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_acquisitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('bot_id');
            $table->text('external_chat_id');
            $table->text('source');             // SEC-05: schema-validated token, never a flow id
            $table->text('context')->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bot_id')->references('id')->on('telegram_bots');

            $table->unique(['tenant_id', 'bot_id', 'external_chat_id']);
        });

        DB::statement('ALTER TABLE telegram_acquisitions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_acquisitions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON telegram_acquisitions
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        // Write-once physics: INSERT column-granted (created_at excluded —
        // the DB default fills it), UPDATE/DELETE revoked outright.
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON telegram_acquisitions FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, bot_id, external_chat_id, source, context) ON telegram_acquisitions TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_acquisitions');
    }
};
