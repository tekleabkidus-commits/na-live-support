<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S07 step 9 — `ticket_list_items.older_channel_activity` (Doc 05 EC-04's
 * list half, the closing row): when one customer runs TWO conversations
 * (business account + direct bot) with open-family tickets, the inbox
 * "groups by customer with a dual-channel badge; agents are warned when
 * replying in the *older-activity* conversation". The projector's group
 * recompute sets `dual_channel` on every row of the group and THIS flag on
 * the rows whose conversation is NOT the customer's most recent activity —
 * the row-level truth the list badge and the thread/composer warning
 * (steps 10/11) both read.
 *
 * R-19b class: a derived BOOLEAN, not a timestamp — no ordering key, cursor
 * or metric reads it; the tags-column precedent (step 4) applies verbatim.
 * Additive; the app column grants are re-issued with the new column in both
 * lists — the denied sets (created_at / tenant_id / ticket_id) UNCHANGED,
 * so InboxGrantPhysicsTest's filed analysis stands as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_list_items ADD COLUMN older_channel_activity boolean NOT NULL DEFAULT false');

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ticket_list_items FROM app');
        DB::statement('GRANT INSERT (ticket_id, tenant_id, conversation_id, number, status, priority, pending_reason, source, assignee_id, department_id, queue_id, customer_id, customer_display_name, channel, dual_channel, channel_unreachable, subject, snippet, snippet_kind, language, sentiment, sla_state, ticket_created_at, last_activity_at, last_message_created_at, window_expires_at, sla_due_at, updated_at, tags, older_channel_activity) ON ticket_list_items TO app');
        DB::statement('GRANT UPDATE (conversation_id, number, status, priority, pending_reason, source, assignee_id, department_id, queue_id, customer_id, customer_display_name, channel, dual_channel, channel_unreachable, subject, snippet, snippet_kind, language, sentiment, sla_state, ticket_created_at, last_activity_at, last_message_created_at, window_expires_at, sla_due_at, updated_at, tags, older_channel_activity) ON ticket_list_items TO app');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_list_items DROP COLUMN older_channel_activity');
    }
};
