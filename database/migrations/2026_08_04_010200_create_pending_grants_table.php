<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pending high-risk grants — Doc 03 T-06/FR-08b: when `rbac.four_eyes` is on,
 * a high-risk grant writes a row here instead of taking effect. The RESOLVER
 * IGNORES pending rows entirely (they never enter a capability map); approval
 * by a DISTINCT second admin applies the grant and audits; auto-expire 24 h
 * (DB time, EC-09). FS-04: an approval-job failure leaves rows 'pending' —
 * visible in the UI, granting nothing. Fail-safe by construction.
 *
 * Approval rights are ruling R-9's: holders of admin.roles.manage (role
 * grants) / admin.overrides.manage (override grants); the requester can never
 * decide their own row — enforced at the write path AND by the decided_by <>
 * requested_by CHECK below, so even a buggy handler cannot self-approve.
 *
 * Rows transition status, they are never deleted (grant physics as
 * everywhere): pending → approved | rejected | expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('pending_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            // What is being granted, and onto what: a high-risk key onto a
            // role's permission set, or a high-risk allow override onto a user.
            $table->text('subject_type');
            $table->uuid('role_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->text('permission');

            $table->uuid('requested_by');
            $table->text('reason');

            $table->text('status')->default('pending');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampTz('expires_at');

            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->foreign('decided_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE pending_grants ADD CONSTRAINT pending_grants_subject_check CHECK ((subject_type = 'role' AND role_id IS NOT NULL AND user_id IS NULL) OR (subject_type = 'override' AND user_id IS NOT NULL AND role_id IS NULL))");
        DB::statement("ALTER TABLE pending_grants ADD CONSTRAINT pending_grants_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'expired'))");
        // FR-08b/AC-05: the second admin is DISTINCT — as a table constraint,
        // not only a code path.
        DB::statement('ALTER TABLE pending_grants ADD CONSTRAINT pending_grants_distinct_decider_check CHECK (decided_by IS NULL OR decided_by <> requested_by)');

        DB::statement('CREATE INDEX pending_grants_tenant_status_index ON pending_grants (tenant_id, status)');

        DB::statement('REVOKE DELETE ON pending_grants FROM app');

        DB::statement('ALTER TABLE pending_grants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pending_grants FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON pending_grants
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_grants');
    }
};
