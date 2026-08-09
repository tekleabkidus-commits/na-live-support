<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu designer storage (Doc 05 FR-27, T-06; frames E01–E06).
 *
 * Two tables, one design decision: EDITS live on the menu row (`draft`),
 * PUBLISHES are immutable snapshots in telegram_menu_versions — so "publish is
 * versioned with instant rollback" (FR-27) is a pointer move
 * (`active_version_id`), never a data rewrite, and version history with
 * restore (frame E05) reads an INSERT-ONLY store.
 *
 * - telegram_menus: one row per (bot, language). `created_at` is NOT
 *   ordering/cursor/metric-bearing (bounded per-bot set, no pagination) —
 *   plain DB-time default for hygiene, grants untouched, per the plan's
 *   stated R-19b analysis.
 * - telegram_menu_versions: `created_at` IS the publish instant and orders
 *   the version history — full R-19b treatment (clock_timestamp() default,
 *   INSERT column-revoked). UPDATE and DELETE are revoked entirely: the
 *   SEC-02 insert-only-store posture; a published version can never be
 *   edited, only superseded or rolled back to.
 * - The menus → versions active-pointer FK is added after both tables exist
 *   (cross-reference).
 */
return new class extends Migration
{
    /** telegram_menu_versions: every column EXCEPT the DB-time set. */
    private const VERSION_APP_WRITABLE_COLUMNS = [
        'id', 'tenant_id', 'menu_id', 'version', 'definition', 'published_by',
    ];

    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('telegram_menus', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('bot_id');
            $table->text('language');           // per bot per language (FR-27)
            $table->jsonb('draft')->nullable(); // designer working copy
            $table->uuid('active_version_id')->nullable(); // FK added below

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bot_id')->references('id')->on('telegram_bots');

            $table->unique(['tenant_id', 'bot_id', 'language']);
        });

        Schema::create('telegram_menu_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('menu_id');
            $table->integer('version');
            $table->jsonb('definition');        // buttons/rows/targets snapshot
            $table->uuid('published_by');

            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()')); // R-19b: IS published_at

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('menu_id')->references('id')->on('telegram_menus');
            $table->foreign('published_by')->references('id')->on('users');

            $table->unique(['menu_id', 'version']);
        });

        DB::statement('ALTER TABLE telegram_menus ADD CONSTRAINT telegram_menus_active_version_foreign FOREIGN KEY (active_version_id) REFERENCES telegram_menu_versions (id)');

        // Menus: rows persist with the bot (archived, never dropped).
        DB::statement('REVOKE DELETE ON telegram_menus FROM app');

        // Versions: immutable INSERT-only store (SEC-02 posture) with the
        // R-19b column revoke on created_at.
        $columns = implode(', ', self::VERSION_APP_WRITABLE_COLUMNS);
        DB::statement('REVOKE INSERT ON telegram_menu_versions FROM app');
        DB::statement("GRANT INSERT ({$columns}) ON telegram_menu_versions TO app");
        DB::statement('REVOKE UPDATE ON telegram_menu_versions FROM app');
        DB::statement('REVOKE DELETE ON telegram_menu_versions FROM app');

        foreach (['telegram_menus', 'telegram_menu_versions'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tableName} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$tableName}
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_menu_versions');
        Schema::dropIfExists('telegram_menus');
    }
};
