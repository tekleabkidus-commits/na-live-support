<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C15's drawn Stop (S07 step 14, Unit D): a bulk batch the requester can halt
 * mid-run. The flag is observed LIVE by TicketBulkJob before each item — the
 * same live-at-execution posture SEC-04 gives permissions — and the items that
 * never ran finish as failed('cancelled'), which keeps them inside the existing
 * item status CHECK and gives the drawer an honest per-row reason. The bulk
 * pipeline is Doc 07's own step-7 module, so this is in-scope growth, not a
 * core edit (the step-14 batch record's own ruling).
 *
 * R-19b per-column analysis: `cancel_requested_at` is a LATCH read as
 * IS NOT NULL — it participates in no ordering key, cursor, or reported
 * metric (the drawer renders item counts, never this stamp), so per R-19b's
 * scope sentence it takes no clock default, no column grant, and no
 * schema-derived guard; the single writer (BulkJobStore::requestCancel)
 * stamps it with clock_timestamp() for consistency with its siblings.
 * Expand-only (C-12): one nullable column, no constraint surgery.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_bulk_jobs ADD COLUMN cancel_requested_at timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_bulk_jobs DROP COLUMN cancel_requested_at');
    }
};
