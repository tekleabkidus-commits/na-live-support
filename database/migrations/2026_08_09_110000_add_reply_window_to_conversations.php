<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reply-window state on conversations (Doc 05 FR-21…24, T-04).
 *
 * `reply_window_expires_at` is the SINGLE SOURCE OF TRUTH for the ~24 h
 * on-behalf reply window; the Redis ZSET is only its O(log n) sweep index,
 * rebuildable from this column (FS-05). `reply_window_state` is the
 * last-swept state (open|closing|expired) so the 5-min sweep can detect
 * TRANSITIONS and emit closing|expired once each.
 *
 * R-19b analysis (plan row 4): both columns are APP-WRITABLE by necessity —
 * the expiry is a computed business DEADLINE, not an ordering key, cursor, or
 * reported metric, so no clock_timestamp() default and no column-grant revoke.
 * BUT the VALUES are DB-time-derived: the expiry computes from the inbound
 * message's DB-time `created_at` (never telegram_date, EC-13), and
 * calibration's "now" is `clock_timestamp()` inside the UPDATE expression
 * (WindowCalibration) — pinned by test, not by grant, because the app must be
 * able to write a computed deadline.
 *
 * Expand-contract: add-nullable only (C-12). Adding columns takes no long
 * lock; the migration-safety linter's HOT-table rule is about index DDL,
 * which this migration does not do.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE conversations ADD COLUMN reply_window_expires_at timestamptz(6) NULL');
        DB::statement('ALTER TABLE conversations ADD COLUMN reply_window_state text NULL');
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_reply_window_state_check CHECK (reply_window_state IS NULL OR reply_window_state IN ('open', 'closing', 'expired'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE conversations DROP COLUMN IF EXISTS reply_window_expires_at');
        DB::statement('ALTER TABLE conversations DROP COLUMN IF EXISTS reply_window_state');
    }
};
