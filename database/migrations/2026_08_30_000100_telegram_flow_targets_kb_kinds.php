<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT — ADDITION (S10 step 13b, packet P7, COUNTERSIGNED at the batch-D window
 * 2026-08-30): the telegram_flow_targets kind CHECK grows with Doc 10 FR-19's two menu-bindable
 * KB kinds (`kb_article`, `kb_category`). CONSTRAINT-ONLY: no column, no grant, no data change
 * (a CHECK swap on an existing column is expand-contract neutral — every existing row satisfies
 * the wider set; the P5 ai_decisions precedent). Target ids stay OPAQUE REFS in this table
 * (SEC-05's indirection: never executed as flow ids); existence is validated at BIND time
 * (MenuDesigner::bindTarget, same commit) and PUBLISHED-ness is render-time truth — an article
 * that later unpublishes degrades at render (KbFlowRenderer's tombstone key), never strands
 * the menu. The tap-handling flow engine remains Doc 12's ([D12]).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE telegram_flow_targets DROP CONSTRAINT telegram_flow_targets_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE telegram_flow_targets ADD CONSTRAINT telegram_flow_targets_type_check CHECK (target_type IN (
                'kb_flow', 'form', 'ai_chat', 'human_handoff', 'url', 'kb_article', 'kb_category'
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE telegram_flow_targets DROP CONSTRAINT telegram_flow_targets_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE telegram_flow_targets ADD CONSTRAINT telegram_flow_targets_type_check CHECK (target_type IN (
                'kb_flow', 'form', 'ai_chat', 'human_handoff', 'url'
            ))
            SQL);
    }
};
