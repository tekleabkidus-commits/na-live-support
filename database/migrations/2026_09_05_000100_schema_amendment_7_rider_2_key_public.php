<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 7 — RIDER 2 (Doc 13, S13 Batch C; countersigned in shape by the advisor's
 * ruling of 2026-09-05, filed in ANSWERS before this migration — R-D13-7 applied: not secret
 * material). `widgets.key_public`: the widget key exactly as the host page's snippet carries it
 * (Doc 02 FR-21 makes it a PUBLIC identifier bound to the origin allowlist). Written ONCE at
 * creation by the key issuer's caller (INSERT granted on the column); UPDATE app-DENIED, so it
 * can never be rewritten from the app role; never a hash input — Doc 02's key resolution keeps
 * hashing the presented key and does not read this column. The Install tab renders the drawn
 * snippet from it (B3-S08 J06); the D13-D06 shown-once interim is struck.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE widgets ADD COLUMN key_public text');
        // expand-contract: nullable; existing rows stay null until their operator re-creates the widget
        DB::statement('GRANT INSERT (key_public) ON widgets TO app');
        // NO UPDATE grant on key_public: written once, immutable by physics (the 42501 control lives in WidgetGrantPhysicsTest).
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE widgets DROP COLUMN IF EXISTS key_public');
    }
};
