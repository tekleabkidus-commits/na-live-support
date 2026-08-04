<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Message revisions (Doc 04 FR-21/FR-22, BR-03) — the retained originals behind every
 * edit and delete. Betting disputes require them (BR-03); exposure is gated
 * `conversations.history.full` with access AUDITED (AC-06).
 *
 * APPEND-ONLY BY GRANT PHYSICS: REVOKE UPDATE AND DELETE from app — a revision is a
 * historical fact, and history that can be edited is not history (the audit_log
 * precedent, N-13's posture). Rows leave only when retention.revisions.months drops
 * them via the migrator (T-08, step 9).
 *
 * (message_id, message_created_at) addresses the partitioned messages row — a
 * composite mirror of its (id, created_at) PK, so the pair is a real FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('message_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('message_id');
            $table->timestampTz('message_created_at');
            $table->uuid('conversation_id');

            // 'edit' preserves the prior content; 'delete' records the tombstone
            // moment (FR-21's "message deleted by customer", FR-22's staff side).
            $table->text('revision_kind');
            $table->text('source');
            $table->uuid('actor_id')->nullable();

            $table->text('prior_body')->nullable();
            $table->jsonb('prior_media')->nullable();

            $table->timestampsTz();             // C-03; created_at = revised-at, DB time (C-11)

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('conversation_id')->references('id')->on('conversations');
        });

        DB::statement("ALTER TABLE message_revisions ADD CONSTRAINT message_revisions_kind_check CHECK (revision_kind IN ('edit', 'delete'))");
        DB::statement("ALTER TABLE message_revisions ADD CONSTRAINT message_revisions_source_check CHECK (source IN ('customer', 'staff'))");
        DB::statement(<<<'SQL'
            ALTER TABLE message_revisions
                ADD CONSTRAINT message_revisions_message_foreign
                FOREIGN KEY (message_id, message_created_at) REFERENCES messages (id, created_at)
            SQL);

        DB::statement('CREATE INDEX message_revisions_message_index ON message_revisions (tenant_id, message_id)');
        DB::statement('CREATE INDEX message_revisions_conversation_index ON message_revisions (tenant_id, conversation_id)');

        // Append-only physics.
        DB::statement('REVOKE UPDATE, DELETE ON message_revisions FROM app');

        DB::statement('ALTER TABLE message_revisions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE message_revisions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON message_revisions
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_revisions');
    }
};
