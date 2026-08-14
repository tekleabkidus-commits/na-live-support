<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 2 — GRANT DELTA (filed in ANSWERS before this shipped):
 * the merge saga's first execution was REFUSED BY THE GRANT (42501) because
 * the countersigned fence on customer_notes UPDATE — (pinned, updated_at) —
 * missed that FR-12 re-points notes to the survivor and FR-13/FS-02 re-point
 * them back. customer_id joins the grant; body/author/created_at/id/tenant
 * stay denied (no note-body edit has a document line).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE (customer_id, pinned, updated_at) ON customer_notes TO app');
    }

    public function down(): void
    {
        DB::statement('REVOKE UPDATE ON customer_notes FROM app');
        DB::statement('GRANT UPDATE (pinned, updated_at) ON customer_notes TO app');
    }
};
