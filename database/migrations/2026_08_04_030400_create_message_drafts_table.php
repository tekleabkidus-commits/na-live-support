<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side drafts (Doc 04 FR-20, T-07): one row per (user, conversation), unique;
 * last-writer-wins guarded by updated_at (EC-06's loser gets the
 * "draft updated on another device" toast). The local IndexedDB half lives in the
 * client; this table is what makes US-03's "crashed laptop → waiting on my phone"
 * true (AC-08's ≤10 s stale recovery).
 *
 * "Cleared on send" is a SOFT clear (body NULL + cleared_at): ADR 0003 revokes DELETE
 * from app on every tenant table, and the (user, conversation) row is simply reused by
 * the next draft. `draft.saved` is LOCAL ONLY — nothing here publishes to the bus, and
 * FR-26's test asserts its absence from the catalog/outbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('message_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('user_id');
            $table->uuid('conversation_id');

            $table->text('body')->nullable();
            $table->timestampTz('cleared_at')->nullable();

            $table->timestampsTz();             // C-03; updated_at is the LWW guard (T-07)

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('conversation_id')->references('id')->on('conversations');
        });

        // FR-06's body bound applies to drafts too — a draft that could never send is
        // not a draft.
        DB::statement('ALTER TABLE message_drafts ADD CONSTRAINT message_drafts_body_size_check CHECK (body IS NULL OR octet_length(body) <= 16384)');

        // T-07: unique (user_id, conversation_id) — tenant-prefixed per C-02.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX message_drafts_user_conversation_unique
                ON message_drafts (tenant_id, user_id, conversation_id)
            SQL);

        DB::statement('REVOKE DELETE ON message_drafts FROM app');

        DB::statement('ALTER TABLE message_drafts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE message_drafts FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON message_drafts
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_drafts');
    }
};
