<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-08/EC-09's legal-hold half (expand-only, C-12):
 *
 * - `conversations.legal_hold` — the per-conversation flag the retention job
 *   honors. Boolean with a default, added nullable-equivalent (a DEFAULT false
 *   backfills instantly on PG16 without a table rewrite).
 * - `legal_hold_messages` — HOLD STORAGE: rows copied out of a partition the
 *   moment retention drops it, exactly the columns a dispute review needs.
 *   Unpartitioned (held rows are rare by construction), REVOKE DELETE (held
 *   content leaving requires the erasure workflow, Doc 17), RLS like every
 *   tenant table. The Doc 18 runbook (EC-09's bucket) operates it; this
 *   migration only makes the physics exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement('ALTER TABLE conversations ADD COLUMN legal_hold boolean NOT NULL DEFAULT false');

        Schema::create('legal_hold_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // the ORIGINAL message id (C-01)
            $table->uuid('tenant_id');          // C-02

            $table->uuid('conversation_id');
            $table->text('direction');
            $table->text('author_type');
            $table->uuid('author_id')->nullable();
            $table->text('kind');
            $table->text('body')->nullable();
            $table->jsonb('media');
            $table->jsonb('channel_meta');
            $table->timestampTz('message_created_at');
            // The message's full revision history (prior bodies), carried onto
            // the hold row — revision rows themselves cannot outlive their
            // partition (the FK), but held EVIDENCE must (BR-03).
            $table->jsonb('revisions')->nullable();
            $table->timestampTz('held_at');     // stamped by the retention run, DB time

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement('CREATE INDEX legal_hold_messages_conversation_index ON legal_hold_messages (tenant_id, conversation_id)');

        DB::statement('REVOKE DELETE ON legal_hold_messages FROM app');
        DB::statement('ALTER TABLE legal_hold_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE legal_hold_messages FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON legal_hold_messages
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_hold_messages');
        DB::statement('ALTER TABLE conversations DROP COLUMN IF EXISTS legal_hold');
    }
};
