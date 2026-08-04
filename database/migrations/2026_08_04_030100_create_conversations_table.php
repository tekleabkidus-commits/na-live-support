<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations (Doc 04 FR-01..FR-04) — the permanent per-customer-per-channel-endpoint
 * thread. NOT a ticket: tickets (Doc 06) are bounded work items referencing message
 * ranges within this thread.
 *
 * Shape decisions and their spec anchors:
 * - FR-01's identity is the unique index (tenant_id, channel, channel_ref_id,
 *   external_chat_id) — one Telegram user ↔ one bot = one conversation forever.
 * - `channel` is TEXT validated against the AdapterRegistry at the repository
 *   boundary, NOT a CHECK constraint enumerating channel names: SS-02's zero-core-edits
 *   guarantee means a future adapter registers itself without a core migration, and a
 *   channel list frozen in DDL would be exactly such an edit. FR-02's
 *   "(telegram|webchat)" enumerates the channels Docs 05/13 ship, not a closed set.
 * - `business_connection_id` ships as a nullable column per FR-02; NOTHING populates
 *   it this session (Doc 05's field, schema-only by session ruling).
 * - FR-03 "conversations are never deleted" is GRANT PHYSICS (REVOKE DELETE from app,
 *   ADR 0003), not policy.
 * - FR-04/BR-05's one-open-ticket invariant is the single `open_ticket_id` column —
 *   there is structurally no room for two open tickets — plus the repository's atomic
 *   claim (UPDATE … WHERE open_ticket_id IS NULL) refusing with `open_ticket_exists`.
 *   No FK: the tickets table is Doc 06's.
 * - `unread_count` is the persisted ROLLUP only; per-viewer counts live in Redis
 *   (FR-02), volatile by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->text('channel');
            $table->text('channel_ref_id');
            $table->text('business_connection_id')->nullable();
            $table->uuid('customer_id');
            $table->text('external_chat_id');

            $table->text('state')->default('active');
            $table->uuid('open_ticket_id')->nullable();

            $table->timestampTz('last_customer_message_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();
            $table->integer('unread_count')->default(0);

            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
        });

        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_state_check CHECK (state IN ('active', 'blocked'))");

        // conversations is a HOT table (ci/migration-safety.php): raw DDL indexes.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_identity_unique
                ON conversations (tenant_id, channel, channel_ref_id, external_chat_id)
            SQL);
        DB::statement('CREATE INDEX conversations_tenant_recency_index ON conversations (tenant_id, last_customer_message_at DESC)');
        DB::statement('CREATE INDEX conversations_customer_index ON conversations (tenant_id, customer_id)');

        // FR-03 as physics (ADR 0003).
        DB::statement('REVOKE DELETE ON conversations FROM app');

        DB::statement('ALTER TABLE conversations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE conversations FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON conversations
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
