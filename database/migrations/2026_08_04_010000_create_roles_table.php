<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles — Doc 03 T-01/FR-02: unlimited per tenant, permissions as a jsonb
 * string[] against the closed catalog, a per-module scope map, `is_system` for
 * the nine seeded templates (editable, never deletable while assigned).
 *
 * Shape decisions carried by rulings:
 * - SOFT DELETE (ruling R-11): ADR 0003 revokes DELETE from the app role on
 *   every business table (Doc 01 BR-02), so FR-08c's "delete role" is
 *   `deleted_at` — fully serving the requirement (blocked while assigned,
 *   count + reassign shortcut) while the grant physics stay intact.
 *   `rbac.role.deleted` is still emitted.
 * - `version` is EC-03's optimistic lock: two admins editing the same role
 *   race on it; the loser sees the winner's diff and can re-apply.
 * - `seeded_fingerprint` is EC-07's never-overwrite-edited contract: the
 *   seeder stamps the content hash it wrote; a release update touches a
 *   template ONLY while the current content still matches that hash —
 *   otherwise the operator edited it and changes ship as changelog
 *   suggestions in the roles UI, never as silent overwrites.
 * - `ip_allowlist` is Doc 02 SEC-05's per-ROLE IP allowlist (null = no
 *   restriction), wired by this session's step 7 alongside `auth.ip.blocked`.
 * - Scope semantics (FR-04, documented limitation): scope comes from the role
 *   ALONE; overrides grant/deny the verb inside the role's scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->text('name');
            $table->text('description')->nullable();
            // A design-system color token name (B6-S07 draws the dot from the
            // palette), never a raw hex — N-03 applies to stored appearance too.
            $table->text('color');

            // FR-01/BR-03: members must exist in the code-side catalog; the
            // boot check logs-and-ignores orphans (T-01/FS-03), the resolver
            // intersects with the catalog so an orphan can never grant.
            $table->jsonb('permissions')->default('[]');

            // Per-module scope map {module: "Own"|"Team"|"Dept"|"All"} for the
            // scoped modules only. A scoped module ABSENT from the map fails
            // closed (NFR-04): the resolver treats missing as an empty set,
            // never as All.
            $table->jsonb('scopes')->default('{}');

            $table->boolean('is_system')->default(false);
            $table->text('seeded_fingerprint')->nullable();

            $table->jsonb('ip_allowlist')->nullable();

            $table->integer('version')->default(1);

            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        // FR-02: name unique per tenant — among LIVE roles, so a deleted
        // role's name is reusable (the delete already passed FR-08c's
        // nobody-assigned guard).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX roles_tenant_name_unique
                ON roles (tenant_id, name) WHERE deleted_at IS NULL
            SQL);
        DB::statement('CREATE INDEX roles_tenant_id_index ON roles (tenant_id)');

        // ADR 0003 physics: soft delete only; RLS enabled AND forced.
        DB::statement('REVOKE DELETE ON roles FROM app');

        DB::statement('ALTER TABLE roles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE roles FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON roles
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
