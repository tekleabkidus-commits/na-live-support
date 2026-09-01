<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 — RIDER 4 (filed 2026-09-02, before this migration): FR-24's
 * second trigger point (Doc 09 ai.resolved — a conversation with NO ticket) needs
 * an armable survey row: ticket_id goes nullable, conversation_id arrives as the
 * delivery anchor, and a CHECK keeps every survey anchored somewhere. Loosening a
 * NOT NULL + adding a nullable column = expand-contract neutral (C-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");
        DB::statement('ALTER TABLE surveys ALTER COLUMN ticket_id DROP NOT NULL');
        DB::statement('ALTER TABLE surveys ADD COLUMN conversation_id uuid REFERENCES conversations (id)');
        DB::statement('ALTER TABLE surveys ADD CONSTRAINT surveys_anchor_check CHECK (ticket_id IS NOT NULL OR conversation_id IS NOT NULL)');
        DB::statement('GRANT INSERT (conversation_id) ON surveys TO app');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE surveys DROP CONSTRAINT IF EXISTS surveys_anchor_check');
        DB::statement('ALTER TABLE surveys DROP COLUMN IF EXISTS conversation_id');
    }
};
