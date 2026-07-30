<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant (Doc 01 FR-01, FR-05, BR-01).
 *
 * One row = one operator brand instance. An operator running three brands gets
 * three rows and there is no cross-brand join anywhere in tenant scope (BR-01);
 * cross-brand reporting is a platform-console concern.
 *
 * This table and ref_* are the only tables without tenant_id (C-02). It still
 * gets RLS — keyed on `id` rather than `tenant_id` — so one tenant cannot even
 * enumerate the existence of another (see the RLS migration and SEC-06).
 *
 * `state` is guarded by a database trigger in the next migration. The CHECK
 * constraint here bounds the vocabulary; the trigger bounds the transitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();                       // C-01 UUIDv7
            $table->string('name');                              // operator-supplied brand name
            $table->string('slug')->unique();                    // internal, URL-safe handle
            $table->string('state', 16);
            $table->string('region', 32);                        // §19: region-sharding already modelled

            // Reference-data choices (FR-10: tenants select, never define).
            $table->string('default_language_code', 16);
            $table->string('timezone_name', 64);
            $table->string('currency_code', 3);

            // Plan flags and quota overrides are JSONB, not columns: Doc 16 owns
            // the plan model and adding a plan should not be a migration.
            // Quota DEFAULTS live in registries/settings.php; this holds only
            // per-tenant overrides (NFR-06).
            $table->jsonb('plan_flags')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('quota_overrides')->default(DB::raw("'{}'::jsonb"));

            // Lifecycle provenance. state_changed_at is DB-clocked (EC-08: no
            // app-server wall clock ever decides ordering).
            $table->timestampTz('state_changed_at')->default(DB::raw('now()'));
            $table->timestampTz('provisioned_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('archived_at')->nullable();

            $table->timestampsTz();

            $table->foreign('default_language_code')->references('code')->on('ref_languages');
            $table->foreign('timezone_name')->references('code')->on('ref_timezones');
            $table->foreign('currency_code')->references('code')->on('ref_currencies');
        });

        // The state vocabulary, in the database. FR-05's four states and no
        // fifth; §19 notes a future `pending_payment` predecessor, which will be
        // an expand-contract release, not a surprise value.
        DB::statement("
            ALTER TABLE tenants ADD CONSTRAINT tenants_state_check
            CHECK (state IN ('provisioning', 'active', 'suspended', 'archived'))
        ");

        // Suspended-tenant sweeps (BR-05's 30-day cold-summary cutoff) and the
        // platform console's state filter both read by state.
        DB::statement('CREATE INDEX tenants_state_index ON tenants (state)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
