<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S07 step 2 — two additive corrections to step 1's schema, no new columns:
 *
 * 1. THE MENTION DEDUPE INDEX (C-09 as physics for the note.mentioned
 *    consumer): a redelivered note.mentioned must not mint a second bell row.
 *    The projector writes mention rows with the SOURCE EVENT id inside the
 *    payload (identifiers only, Q7) and inserts with insertOrIgnore — this
 *    expression index is what makes the ignore a database refusal rather
 *    than relay care.
 *
 * 2. snippet_kind CHECK gains 'audio' — Doc 04 FR-05's kind set includes
 *    audio; step 1's list missed it (a media-only audio message's snippet
 *    placeholder would have been refused by the CHECK). Expand-only:
 *    drop + re-add with the corrected set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX inbox_notifications_event_dedupe
                ON inbox_notifications (tenant_id, user_id, ((payload->>'event_id')))
                WHERE payload->>'event_id' IS NOT NULL
            SQL);

        DB::statement('ALTER TABLE ticket_list_items DROP CONSTRAINT ticket_list_items_snippet_kind_check');
        DB::statement("ALTER TABLE ticket_list_items ADD CONSTRAINT ticket_list_items_snippet_kind_check CHECK (snippet_kind IN ('text', 'photo', 'video', 'voice', 'audio', 'document', 'sticker', 'location', 'contact'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inbox_notifications_event_dedupe');
        DB::statement('ALTER TABLE ticket_list_items DROP CONSTRAINT ticket_list_items_snippet_kind_check');
        DB::statement("ALTER TABLE ticket_list_items ADD CONSTRAINT ticket_list_items_snippet_kind_check CHECK (snippet_kind IN ('text', 'photo', 'video', 'voice', 'document', 'sticker', 'location', 'contact'))");
    }
};
