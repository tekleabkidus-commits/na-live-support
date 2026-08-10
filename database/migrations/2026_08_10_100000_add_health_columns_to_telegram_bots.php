<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-29/FR-30/FS-02 health columns on the bot registry (Doc 05, S23 step 11).
 *
 * Expand-only (C-12): every column is nullable or defaulted; nothing existing
 * changes shape.
 *
 * R-19b per-column analysis (inherited row 4, the plan's categories):
 * - `health_status` — a STATE word (healthy|degraded|down), not a timestamp;
 *   CHECK-constrained. App-written by the rollup (the sole writer class).
 * - `health_changed_at` — PINNED-PROVENANCE (the calibration pattern):
 *   app-granted, but the writer sets it with clock_timestamp() inside the
 *   UPDATE statement itself, so the value is DB time. It orders nothing and
 *   feeds no metric — it is the "since when" display on the health panel
 *   (B6-S06 F01). Transition-only emission compares health_status, never
 *   this column.
 * - `last_inbound_at` — PINNED-PROVENANCE, exactly the plan's ruling for
 *   `telegram_business_connections.last_inbound_at`: written via
 *   clock_timestamp() in the UPDATE at update-routing time. FS-02 compares
 *   it against a minutes-scale threshold; job latency (seconds) is noise at
 *   that scale, stated in the writer's docblock.
 * - `webhook_pending_updates`, `webhook_last_error` — Telegram-reported
 *   NUMBERS/TEXT (getWebhookInfo), channel metadata, no clock involved.
 * - `webhook_last_error_at` — CHANNEL METADATA (Telegram's last_error_date,
 *   EC-13/R-19 posture): display-only provenance from the channel, stored as
 *   reported, participates in no ordering and no metric.
 * - `last_polled_at` — PINNED-PROVENANCE via clock_timestamp() in the poll's
 *   UPDATE; freshness display ("Refreshed 12 s ago", F01) only.
 *
 * The original migration REVOKEd table-level INSERT/UPDATE and granted
 * explicit columns; these new columns are granted for UPDATE only — no
 * INSERT path names them (defaults fill on insert), so the poll job and the
 * router are the only writers, both via UPDATE.
 */
return new class extends Migration
{
    private const NEW_UPDATE_COLUMNS = [
        'health_status', 'health_changed_at', 'webhook_pending_updates',
        'webhook_last_error', 'webhook_last_error_at', 'last_inbound_at', 'last_polled_at',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::table('telegram_bots', function ($table): void {
            $table->text('health_status')->default('healthy');
            $table->timestampTz('health_changed_at', 6)->nullable();
            $table->integer('webhook_pending_updates')->default(0);
            $table->text('webhook_last_error')->nullable();
            $table->timestampTz('webhook_last_error_at', 6)->nullable();
            $table->timestampTz('last_inbound_at', 6)->nullable();
            $table->timestampTz('last_polled_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE telegram_bots ADD CONSTRAINT telegram_bots_health_status_check CHECK (health_status IN ('healthy', 'degraded', 'down'))");

        $columns = implode(', ', self::NEW_UPDATE_COLUMNS);
        DB::statement("GRANT UPDATE ({$columns}) ON telegram_bots TO app");
    }

    public function down(): void
    {
        Schema::table('telegram_bots', function ($table): void {
            $table->dropColumn([
                'health_status', 'health_changed_at', 'webhook_pending_updates',
                'webhook_last_error', 'webhook_last_error_at', 'last_inbound_at', 'last_polled_at',
            ]);
        });
    }
};
