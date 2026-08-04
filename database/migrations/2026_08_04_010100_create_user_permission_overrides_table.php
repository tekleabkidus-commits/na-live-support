<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User permission overrides — Doc 03 FR-04: {permission, effect allow|deny,
 * expires_at?, granted_by, reason}, precedence User Deny > User Allow > Role >
 * implicit deny.
 *
 * - `scope` is RESERVED (§19 scoped-override expansion): the column exists so
 *   the model is ready, and the resolver NEVER reads it in v1 — an override
 *   cannot alter scopes (FR-04's documented limitation). A test pins both
 *   halves.
 * - Soft removal (ruling R-11 physics): `revoked_at` + `revoke_cause`
 *   ('removed' by an admin → rbac.override.removed; 'expired' by the cleanup
 *   job → the row's rbac.override.expired already fired). Expiry TRUTH is the
 *   resolver's DB-time check (FR-09/EC-09); the cleanup job only tidies.
 * - At most one LIVE override per (user, permission) — the precedence contract
 *   assumes it; the partial unique index makes it physics.
 * - SEC-04: reason is mandatory, ≥ 10 chars, enforced at the write path and
 *   audited; the column is NOT NULL so the invariant survives raw writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('user_permission_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->uuid('user_id');
            $table->text('permission');
            $table->text('effect');
            $table->timestampTz('expires_at')->nullable();
            $table->uuid('granted_by');
            $table->text('reason');

            // §19 reserved — resolver never reads it in v1.
            $table->text('scope')->nullable();

            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->text('revoke_cause')->nullable();

            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('granted_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE user_permission_overrides ADD CONSTRAINT user_permission_overrides_effect_check CHECK (effect IN ('allow', 'deny'))");
        DB::statement("ALTER TABLE user_permission_overrides ADD CONSTRAINT user_permission_overrides_revoke_cause_check CHECK (revoke_cause IN ('removed', 'expired'))");

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_permission_overrides_live_unique
                ON user_permission_overrides (tenant_id, user_id, permission)
                WHERE revoked_at IS NULL
            SQL);
        DB::statement('CREATE INDEX user_permission_overrides_user_index ON user_permission_overrides (tenant_id, user_id)');

        DB::statement('REVOKE DELETE ON user_permission_overrides FROM app');

        DB::statement('ALTER TABLE user_permission_overrides ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE user_permission_overrides FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON user_permission_overrides
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
