<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provisioning progress, one row per step (Doc 01 FR-06, EC-04, EC-05, FS-04).
 *
 * THE UNIQUE INDEX IS THE IDEMPOTENCY KEY. EC-05 says a duplicate provisioning
 * trigger — a double-clicked button, a queue retry, two workers racing — must be
 * safe, with "idempotency key = tenant id + step". That is not a flag the job
 * checks and then acts on (check-then-act is a race); it is
 * `unique (tenant_id, step)`, so the SECOND writer's INSERT fails at the
 * database and its worker stops. AC-05 runs provisioning twice concurrently and
 * asserts one consistent tenant.
 *
 * `status` also gives FS-04 its teeth: a failed step is recorded as failed with
 * an error reference, the tenant stays `provisioning`, and Retry is safe because
 * completed steps cannot be re-entered. A tenant is never partially "active" —
 * `active` is set once, after the last step, by the lifecycle service.
 *
 * §9 requires the console to show "the failed step, error reference id, and
 * Retry", which is why error_ref is stored and the error TEXT is not: the text
 * goes to logs where it can carry detail safely, and the operator gets an id to
 * quote (C-13 — nothing that might hold a secret is surfaced here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_provisioning_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('step', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('error_ref')->nullable();          // quotable id; detail lives in logs
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'step']);          // EC-05 idempotency key
        });

        DB::statement("
            ALTER TABLE tenant_provisioning_steps ADD CONSTRAINT tenant_provisioning_steps_status_check
            CHECK (status IN ('running', 'completed', 'failed'))
        ");

        DB::statement('CREATE INDEX tenant_provisioning_steps_tenant_id_index ON tenant_provisioning_steps (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provisioning_steps');
    }
};
