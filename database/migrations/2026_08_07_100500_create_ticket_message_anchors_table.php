<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket → message-range links (Doc 06 BR-02) — THE boundary table. Messages belong to
 * conversations (Doc 04 primacy); tickets reference RANGES over them. Merge and split
 * move THESE rows, never message rows: FR-20's "link rewrite in one transaction" is an
 * UPDATE of `ticket_id` here, and nothing in this module ever updates a message's
 * conversation.
 *
 * - `kind`: `transcript_anchor` (FR-11's handoff packet range) or `split_selection`
 *   (FR-21's selected-message range; AC-05 requires the anchors to resolve to the
 *   exact selected messages).
 * - NO foreign key onto messages: `messages` is partitioned with composite PK
 *   (id, created_at) and retention DROPs partitions — an FK from here would block the
 *   drop (the step-2/S20 revisions-FK discovery, deliberately not repeated). Message
 *   ids are UUIDv7 and anchor resolution tolerates retention-expired ranges by
 *   construction (the range is provenance, not a JOIN dependency).
 * - `from_message_id`/`to_message_id` bound the range in (created_at, id) order;
 *   equal ids = a single-message range.
 * - DELETE revoked (ADR 0003): links move on merge/unmerge (UPDATE), they never vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ticket_message_anchors', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('ticket_id');
            $table->uuid('conversation_id');
            $table->uuid('from_message_id');
            $table->uuid('to_message_id');
            $table->text('kind');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('ticket_id')->references('id')->on('tickets');
            $table->foreign('conversation_id')->references('id')->on('conversations');

            $table->index(['ticket_id', 'kind']);
        });

        DB::statement("ALTER TABLE ticket_message_anchors ADD CONSTRAINT ticket_message_anchors_kind_check CHECK (kind IN ('transcript_anchor', 'split_selection'))");

        DB::statement('REVOKE DELETE ON ticket_message_anchors FROM app');

        DB::statement('ALTER TABLE ticket_message_anchors ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ticket_message_anchors FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ticket_message_anchors
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_message_anchors');
    }
};
