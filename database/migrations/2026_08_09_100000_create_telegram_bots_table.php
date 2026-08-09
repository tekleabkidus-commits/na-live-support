<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram bot registry (Doc 05 FR-01/FR-04/FR-05; rulings A-19, A-23).
 *
 * Shape decisions and their spec anchors:
 * - `bot_token` is the A-19 interim store: encrypted cast at the model (the
 *   webhook_credentials.hmac_secret / D-5 posture), $hidden, scrubber-covered
 *   (NFR-06), never displayed (SEC-01 — re-entry to change). The C-13 vault
 *   handoff is filed: on the Doc 18 vault this column becomes a vault
 *   reference by expand-contract migration.
 * - `key_id` ties the bot to its S15 route identity: the unguessable
 *   /hooks/telegram/{bot_key} segment is a tenant_keys row and the webhook
 *   secret lives in webhook_credentials (Doc 02 FR-23) — Doc 05 registration
 *   is the production write path for both (BUILD-STATUS S15 standing note).
 *   Nullable until step 4's provisioner writes it.
 * - `mode` is per-bot entity configuration, NOT a settings-registry key
 *   (ruling A-23): topology is per-bot (BR-01 allows one bot ↔ many
 *   accounts and multi-bot tenants), so a tenant singleton cannot carry it.
 * - FR-04: deletion archives (`status = archived`, webhook deleted,
 *   conversations stay readable) — REVOKE DELETE makes row removal
 *   impossible for the app role, so "archived, never dropped" is physics.
 * - R-19b: `created_at` participates in the registry listing's cursor
 *   (C-05 orders (created_at, id)) — clock_timestamp() default, INSERT and
 *   UPDATE both column-revoked, guard in TelegramGrantPhysicsTest.
 *   `updated_at` is Eloquent bookkeeping: no ordering key, no cursor, no
 *   reported metric reads it — app-written, per the plan's stated analysis.
 */
return new class extends Migration
{
    /** Every column EXCEPT the DB-time set (the tickets-migration pattern). */
    private const APP_WRITABLE_COLUMNS = [
        'id', 'tenant_id', 'telegram_bot_id', 'username', 'display_name',
        'bot_token', 'key_id', 'mode', 'status', 'updated_at',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_bots', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->bigInteger('telegram_bot_id');   // getMe id (FR-01 validation)
            $table->text('username');                // @… from getMe
            $table->text('display_name');            // first_name from getMe
            $table->text('bot_token')->nullable();   // A-19 encrypted cast (model, step 2)
            $table->uuid('key_id')->nullable();      // S15 route identity (step 4)

            $table->text('mode')->default('direct'); // A-23 per-bot setting
            $table->text('status')->default('active');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()')); // R-19b
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('key_id')->references('id')->on('tenant_keys');

            $table->unique(['tenant_id', 'telegram_bot_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("ALTER TABLE telegram_bots ADD CONSTRAINT telegram_bots_mode_check CHECK (mode IN ('direct', 'business', 'hybrid'))");
        DB::statement("ALTER TABLE telegram_bots ADD CONSTRAINT telegram_bots_status_check CHECK (status IN ('active', 'archived'))");

        // FR-04: archived, never dropped — physics (ADR 0003 pattern).
        DB::statement('REVOKE DELETE ON telegram_bots FROM app');

        // R-19a/R-19b: the app role cannot NAME created_at, either door.
        $columns = implode(', ', self::APP_WRITABLE_COLUMNS);
        DB::statement('REVOKE INSERT ON telegram_bots FROM app');
        DB::statement("GRANT INSERT ({$columns}) ON telegram_bots TO app");
        DB::statement('REVOKE UPDATE ON telegram_bots FROM app');
        DB::statement("GRANT UPDATE ({$columns}) ON telegram_bots TO app");

        DB::statement('ALTER TABLE telegram_bots ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_bots FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON telegram_bots
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
    }
};
