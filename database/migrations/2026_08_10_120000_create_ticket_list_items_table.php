<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The denormalized list row (Doc 06 T-08's own words: "List reads go through
 * Doc 07's read models; this module owns writes and the canonical row" — this
 * table IS the read model's row store; Doc 07 T-01 hydrates list pages from it
 * by id batch; the view ZSETs hold only ids + sort scores).
 *
 * ONE ROW PER TICKET, maintained exclusively by the list-projector consumer
 * (ADR 0011) from ticket.* / message.* / telegram.window.* events. Never the
 * source of truth: NFR-07's consistency checker compares it to direct scoped
 * queries, FS-02 rebuilds it, and every value is a COPY of a canonical row.
 *
 * R-19b per-column analysis (approved plan §6 + S07 SCHEMA AMENDMENT 1, filed
 * in ANSWERS before this migration shipped):
 * - `created_at` (row birth) — full treatment: clock_timestamp() default,
 *   INSERT and UPDATE both column-revoked for app.
 * - PINNED-PROVENANCE COPIES (app-writable BY DESIGN, written by the projector
 *   as copies of canonical DB-time stamps, never fresh clock reads):
 *   `ticket_created_at` (tickets.created_at — the Unassigned sort key),
 *   `last_activity_at` (the My Open sort key), `last_message_created_at`
 *   (the unread-boundary anchor), `window_expires_at` (Doc 05 window state),
 *   `sla_due_at` (Doc 12 seam; fixture-fed until Doc 12 produces).
 *   ZSET scores derive from these as INTEGER MICROSECONDS (R-23).
 * - `updated_at` — projection maintenance metadata, never an ordering key;
 *   the projector writes it with clock_timestamp() in the UPDATE statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ticket_list_items', function (Blueprint $table): void {
            $table->uuid('ticket_id')->primary();   // one row per ticket; the ticket's own UUIDv7 (C-01 satisfied by identity)
            $table->uuid('tenant_id');              // C-02
            $table->uuid('conversation_id');

            $table->bigInteger('number');           // human-facing ticket number
            $table->text('status');
            $table->text('priority');
            $table->text('pending_reason')->nullable();
            $table->text('source');
            $table->uuid('assignee_id')->nullable();
            $table->uuid('department_id')->nullable();  // no FK — Doc 11 owns departments (A-7 precedent)
            $table->uuid('queue_id')->nullable();       // Doc 11 seam, fixture-fed
            $table->uuid('customer_id')->nullable();
            $table->text('customer_display_name')->nullable();

            $table->text('channel');
            $table->boolean('dual_channel')->default(false);   // Doc 05 EC-04 grouping badge
            $table->boolean('channel_unreachable')->default(false); // the Rescue view predicate
            $table->text('subject')->nullable();
            $table->text('snippet')->nullable();
            $table->text('snippet_kind')->default('text');     // EC-11: 'text' | media placeholder key
            $table->text('language')->nullable();
            $table->text('sentiment')->nullable();             // Doc 09 seam, fixture-fed
            $table->text('sla_state')->nullable();             // Doc 12 seam: warned|breached

            // Pinned-provenance copies (see docblock) — timestamptz(6), app-writable.
            $table->timestampTz('ticket_created_at', 6)->nullable();
            $table->timestampTz('last_activity_at', 6)->nullable();
            $table->timestampTz('last_message_created_at', 6)->nullable();
            $table->timestampTz('window_expires_at', 6)->nullable();
            $table->timestampTz('sla_due_at', 6)->nullable();

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('ticket_id')->references('id')->on('tickets');

            $table->index(['tenant_id', 'assignee_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'conversation_id']);
        });

        DB::statement("ALTER TABLE ticket_list_items ADD CONSTRAINT ticket_list_items_snippet_kind_check CHECK (snippet_kind IN ('text', 'photo', 'video', 'voice', 'document', 'sticker', 'location', 'contact'))");

        DB::statement('ALTER TABLE ticket_list_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ticket_list_items FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ticket_list_items
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        // R-19b: created_at is db-time-only (defaults fill); rows are never
        // deleted by the app (a ticket is never hard-deleted, N-05 — its list
        // row lives as long as it does; view membership changes in the ZSETs).
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ticket_list_items FROM app');
        DB::statement('GRANT INSERT (ticket_id, tenant_id, conversation_id, number, status, priority, pending_reason, source, assignee_id, department_id, queue_id, customer_id, customer_display_name, channel, dual_channel, channel_unreachable, subject, snippet, snippet_kind, language, sentiment, sla_state, ticket_created_at, last_activity_at, last_message_created_at, window_expires_at, sla_due_at, updated_at) ON ticket_list_items TO app');
        DB::statement('GRANT UPDATE (conversation_id, number, status, priority, pending_reason, source, assignee_id, department_id, queue_id, customer_id, customer_display_name, channel, dual_channel, channel_unreachable, subject, snippet, snippet_kind, language, sentiment, sla_state, ticket_created_at, last_activity_at, last_message_created_at, window_expires_at, sla_due_at, updated_at) ON ticket_list_items TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_list_items');
    }
};
