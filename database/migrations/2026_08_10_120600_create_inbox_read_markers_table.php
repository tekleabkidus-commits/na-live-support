<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user read markers (S07 SCHEMA AMENDMENT 1, filed in ANSWERS before this
 * migration shipped — the A-7-amendment-2 precedent): Doc 07 FR-09 requires
 * "unread bold state + count" on the row and FR-15 requires the thread to
 * open "at first unread" — both are per-VIEWER facts, which cannot live on
 * the shared ticket_list_items row. One marker per (user, conversation):
 * unread boundary = messages with created_at AFTER the marker.
 *
 * Unread state/count are computed AT HYDRATION against this marker (the list
 * endpoint overlays the viewer's markers + drafts on the shared page of
 * rows); the ZSETs and pushed counts never carry per-viewer unread numbers.
 *
 * R-19b: `last_read_at` is a PINNED-PROVENANCE COPY of the last-read
 * message's own DB-time created_at (never a fresh clock read — the unread
 * comparison must be in message-stamp space); no ordering key or metric
 * reads this table itself. created_at hygiene default only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('inbox_read_markers', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');
            $table->uuid('conversation_id');

            $table->uuid('last_read_message_id')->nullable();
            $table->timestampTz('last_read_at', 6)->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');

            $table->unique(['tenant_id', 'user_id', 'conversation_id']);
        });

        DB::statement('ALTER TABLE inbox_read_markers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbox_read_markers FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON inbox_read_markers
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE DELETE ON inbox_read_markers FROM app');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_read_markers');
    }
};
