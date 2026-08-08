<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-03's COLUMN TRUTH for the auto-close timer (Doc 06 FR-07, FS-01, SS-03).
 *
 * T-03: "Timers (auto-close, reopen windows): Redis ZSET ticket_timers + column truth".
 * This column is the truth half. The ZSET is only ever an accelerator — a candidate
 * list the 1-minute sweep reads so it does not scan the table — and FS-01 fixes the
 * recovery direction: "Timer ZSET lost → rebuild from columns (truth) … worst case
 * auto-closes delayed, never premature." A rebuild can only be FROM somewhere if the
 * armed deadline exists outside Redis, and this is where.
 *
 * WHY THE DEADLINE IS MATERIALIZED RATHER THAN DERIVED. SS-03: "the change applies to
 * future resolutions only (armed timers keep their value)". A deadline computed at read
 * time as `resolved_at + tickets.autoclose.hours` would silently re-target every armed
 * timer the moment an operator changes the setting — exactly what SS-03 forbids. So the
 * deadline is written ONCE, when the ticket enters `resolved`, as DB-side arithmetic on
 * the trigger-stamped `resolved_at` (`resolved_at + make_interval(hours => :h)`), and a
 * later settings change cannot reach it.
 *
 * WHY IT IS APP-WRITABLE (unlike the R-19b metric set). This is SCHEDULING state, not a
 * measurement: its VALUE is derived in SQL from the DB-stamped `resolved_at`, so no app
 * clock can leak into it (C-11 is satisfied by the arithmetic's operand, not by a
 * grant), and unlike first_response_at it must be clearable/re-writable through the
 * app's own arm/cancel path (FR-07's "timer cancels if the customer replies").
 *
 * The reopen window needs no column of its own: it fires nothing — EC-02's boundary is
 * evaluated on demand, in SQL, against the trigger-stamped `closed_at`.
 *
 * tickets is a HOT table (ci/migration-safety.php): the partial index via raw DDL.
 * Expand-only (C-12): one nullable column, no backfill needed — nothing was armed
 * before this release because nothing could resolve a ticket into a timer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestampTz('auto_close_at', 6)->nullable();
        });

        // A column-level grant holder gains nothing automatically on a NEW column —
        // the same lesson the signal columns taught (see the R-20 trigger migration).
        DB::statement('GRANT INSERT (auto_close_at) ON tickets TO app');
        DB::statement('GRANT UPDATE (auto_close_at) ON tickets TO app');

        // FS-01's rebuild scans exactly the armed set; partial so the index carries
        // only tickets that actually hold a timer, not the whole table.
        DB::statement(<<<'SQL'
            CREATE INDEX tickets_auto_close_armed
                ON tickets (auto_close_at)
             WHERE auto_close_at IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tickets_auto_close_armed');
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('auto_close_at');
        });
    }
};
