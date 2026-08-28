<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 3 — ADDITION (S10 step 7b, packet P5, COUNTERSIGNED): the ai_decisions
 * task CHECK grows 12 → 15 with Doc 10 FR-06's staff-only authoring tasks. CONSTRAINT-ONLY:
 * no column, no grant, no data change (a CHECK swap on an existing column is expand-contract
 * neutral — every existing row satisfies the wider set). TaskCatalog::TASKS moves in the same
 * commit; OrchestratorTest's closed-catalog pin moves with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_decisions DROP CONSTRAINT ai_decisions_task_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ai_decisions ADD CONSTRAINT ai_decisions_task_check CHECK (task IN (
                'classify_message', 'chatbot_turn', 'draft_reply', 'improve_text',
                'summarize_thread', 'translate', 'transcribe_voice', 'ocr_receipt',
                'generate_subject', 'qa_prescore', 'daily_digest', 'anomaly_scan',
                'kb_draft_from_source', 'kb_faq_variants', 'kb_draft_from_conversations'
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_decisions DROP CONSTRAINT ai_decisions_task_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ai_decisions ADD CONSTRAINT ai_decisions_task_check CHECK (task IN (
                'classify_message', 'chatbot_turn', 'draft_reply', 'improve_text',
                'summarize_thread', 'translate', 'transcribe_voice', 'ocr_receipt',
                'generate_subject', 'qa_prescore', 'daily_digest', 'anomaly_scan'
            ))
            SQL);
    }
};
