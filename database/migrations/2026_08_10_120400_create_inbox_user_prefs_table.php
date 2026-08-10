<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user inbox preferences (Doc 07 FR-01 region sizes/collapse, FR-08 view
 * order, FR-10 per-view sort overrides, §9 density, FR-27 sound choices +
 * quick mute, §8 coach-marks dismissal, FR-23 recent searches): one jsonb
 * blob per user, last-writer-wins. These are PREFERENCES, not tenant
 * settings — BR-05: sounds and desktop notifications are per-user choices;
 * nothing here registers in registries/settings.php.
 *
 * R-19b (approved plan §6): NOTHING ordering- or metric-bearing — no
 * ordering key, no cursor, no metric reads this table (BR-06: active-time
 * metrics derive from activity events, never preference state). created_at
 * carries the hygiene default only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('inbox_user_prefs', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');

            $table->jsonb('prefs')->default('{}');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');

            $table->unique(['tenant_id', 'user_id']);
        });

        DB::statement('ALTER TABLE inbox_user_prefs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbox_user_prefs FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON inbox_user_prefs
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE DELETE ON inbox_user_prefs FROM app');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_user_prefs');
    }
};
