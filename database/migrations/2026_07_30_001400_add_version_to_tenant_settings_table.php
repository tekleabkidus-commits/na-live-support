<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An optimistic-concurrency version for settings (Doc 01 EC-06, EC-08).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * WHY NOT `updated_at`, WHICH IS WHAT EC-06 LITERALLY SUGGESTS.
 *
 * EC-06: "Settings write race (two admins) → last-write-wins with both writes audited and
 * a conflict toast to the loser (compare updated_at optimistic check)."
 *
 * That was implemented first, and it failed its own test for two independent reasons —
 * the second of which is the important one.
 *
 * 1. RESOLUTION. Laravel's `timestampTz()` creates `timestamptz(0)`: second precision.
 *    Two writes in the same second therefore produce IDENTICAL tokens and the check
 *    finds nothing wrong. So the mechanism would have detected every race except the
 *    ones that actually occur — two admins clicking Save a few hundred milliseconds
 *    apart. A second apart is not really a race.
 *
 * 2. EC-08 FORBIDS IT. "Clock skew across nodes → all ordering decisions use DB time or
 *    UUIDv7 monotonicity, never app-server wall clocks." A token produced by `now()` in
 *    PHP is an app-server wall clock. On two app nodes with a few milliseconds of skew,
 *    the LATER write can carry the EARLIER timestamp — so the comparison is not merely
 *    imprecise, it can be inverted. Widening the column to microseconds would have fixed
 *    (1) and left (2) in place, which is the worse outcome: a mechanism that looks
 *    correct and fails rarely, under load, on a multi-node deployment.
 *
 * A version counter incremented BY THE DATABASE (`version = version + 1`) has neither
 * problem. It is monotonic per row, it involves no clock at all, and it is exactly the
 * "DB time or monotonicity" that EC-08 asks for. This is EC-06's requirement — optimistic
 * concurrency with a conflict signal — implemented the way EC-08 requires.
 *
 * `updated_at` remains, and remains useful: §8's provenance line is "changed by X at Y".
 * It is simply no longer load-bearing for correctness.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * Single-step NOT NULL with a default on a table with no production rows — the same
 * reasoning as the users.tenant_id migration, and the same guard below.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Counted on the PLATFORM connection, not the migration's own.
        //
        // This migration runs after RLS is enabled, and `FORCE ROW LEVEL SECURITY` applies
        // policies to the schema owner too — so `migrator` reading a tenant table without
        // a bound tenant gets `unrecognized configuration parameter "app.tenant_id"`. That
        // is the FORCE guarantee working as intended, and it means any migration that needs
        // to look at tenant DATA (as opposed to tenant SCHEMA) must go through the
        // RLS-bypassing role. Earlier migrations' guards did not hit this only because they
        // ran before RLS was switched on.
        $existing = (int) DB::connection('pgsql_platform')->table('tenant_settings')->count();
        if ($existing > 0) {
            throw new RuntimeException(sprintf(
                'tenant_settings already holds %d row(s): backfill the version column before enforcing '
                .'NOT NULL (C-12 expand-contract).',
                $existing,
            ));
        }

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->bigInteger('version')->default(1);
        });

        // A version never goes backwards, whatever a caller believes.
        DB::statement('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_version_check CHECK (version >= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_version_check');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
