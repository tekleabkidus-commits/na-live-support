<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S07 step 4 — `ticket_list_items.tags` (jsonb copy of the ticket's tag set):
 * FR-09's row anatomy draws the tag overflow (+N) and FR-06's saved-view
 * filters include tags — both read the LIST row, so the copy belongs on it
 * (the canonical text[] stays on tickets; the projector copies via
 * array_to_json, the read side matches in jsonb). Additive; the app column
 * grants are re-issued with the new column INCLUDED in both lists — the
 * R-19b denied sets are UNCHANGED (created_at / tenant_id / ticket_id), so
 * InboxGrantPhysicsTest's filed analysis stands as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ticket_list_items ADD COLUMN tags jsonb NOT NULL DEFAULT '[]'");

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ticket_list_items FROM app');
        DB::statement('GRANT INSERT (ticket_id, tenant_id, conversation_id, number, status, priority, pending_reason, source, assignee_id, department_id, queue_id, customer_id, customer_display_name, channel, dual_channel, channel_unreachable, subject, snippet, snippet_kind, language, sentiment, sla_state, ticket_created_at, last_activity_at, last_message_created_at, window_expires_at, sla_due_at, updated_at, tags) ON ticket_list_items TO app');
        DB::statement('GRANT UPDATE (conversation_id, number, status, priority, pending_reason, source, assignee_id, department_id, queue_id, customer_id, customer_display_name, channel, dual_channel, channel_unreachable, subject, snippet, snippet_kind, language, sentiment, sla_state, ticket_created_at, last_activity_at, last_message_created_at, window_expires_at, sla_due_at, updated_at, tags) ON ticket_list_items TO app');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_list_items DROP COLUMN tags');
    }
};
