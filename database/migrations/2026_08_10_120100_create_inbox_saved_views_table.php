<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views (Doc 07 FR-06): a view is a DEFINITION — filter set + sort +
 * visible-column config — never a stored query result. Sharing to team or
 * department requires `views.share` (the Doc 03 addition of record); shared
 * views are read-only to consumers with "duplicate to edit" (AC-02).
 *
 * R-19b: no ordering- or metric-bearing timestamp (approved plan §6) — view
 * ORDER in the rail is the per-user arrangement in inbox_user_prefs (FR-08),
 * never a timestamp; created_at carries the hygiene default only and stays
 * app-writable. Soft delete (`deleted_at`) per the R-11.1 precedent: nothing
 * hard-deletes at tenant scope (Doc 01 BR-02), so DELETE is revoked and
 * "delete view" writes the tombstone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('inbox_saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('owner_user_id');

            $table->text('name');
            $table->jsonb('filters')->default('{}');
            $table->jsonb('sort')->default('{}');
            $table->jsonb('columns')->default('{}');

            $table->text('shared_to_type')->nullable();  // team | department
            $table->uuid('shared_to_id')->nullable();    // no FK — Doc 11 owns teams/departments

            $table->timestampTz('deleted_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('owner_user_id')->references('id')->on('users');

            $table->index(['tenant_id', 'owner_user_id']);
        });

        DB::statement("ALTER TABLE inbox_saved_views ADD CONSTRAINT inbox_saved_views_shared_to_type_check CHECK (shared_to_type IN ('team', 'department'))");

        DB::statement('ALTER TABLE inbox_saved_views ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbox_saved_views FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON inbox_saved_views
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE DELETE ON inbox_saved_views FROM app');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_saved_views');
    }
};
