<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable quota counters (Doc 01 NFR-06, FS-05, EC-09).
 *
 * Enforcement reads Redis, because a quota check runs before every message and
 * must cost microseconds (SEC-05: rate limiting happens before expensive work).
 * But Redis here is `redis-volatile` — `allkeys-lru`, no persistence, keys may be
 * evicted at any moment by design (CLAUDE.md §3). A counter that can silently
 * evaporate is not a billing record.
 *
 * So this table is the durable truth and Redis is the fast path. The
 * reconciliation job (FS-05) compares them, repairs drift, and LOGS THE DELTA —
 * the delta is the interesting number, because a persistent one means the fast
 * path is wrong in a way that needs a human, not a nightly repair.
 *
 * One row per (tenant, quota, window). A window is the enforcement period:
 * per-minute quotas get minute windows, per-day quotas get day windows. Storing
 * windows rather than a single running total is what makes EC-09 non-destructive:
 * lowering a quota below current usage blocks FUTURE writes in the CURRENT
 * window and touches no stored data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_quota_usage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('quota', 64);                 // registries/settings.php quota key
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->bigInteger('used')->default(0);

            // Provenance of the last repair, so a support question about a
            // disputed number (R-04) has an answer.
            $table->timestampTz('reconciled_at')->nullable();
            $table->bigInteger('last_drift')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'quota', 'window_start']);
        });

        DB::statement('ALTER TABLE tenant_quota_usage ADD CONSTRAINT tenant_quota_usage_used_check CHECK (used >= 0)');
        DB::statement('ALTER TABLE tenant_quota_usage ADD CONSTRAINT tenant_quota_usage_window_check CHECK (window_end > window_start)');
        DB::statement('CREATE INDEX tenant_quota_usage_tenant_id_index ON tenant_quota_usage (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_quota_usage');
    }
};
