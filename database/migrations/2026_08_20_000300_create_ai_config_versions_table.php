<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_config_versions (SCHEMA AMENDMENT 3 item 2, countersigned; Doc 09 FR-04,
 * FR-18, FR-02's routing, T-02's tenant layers, T-06's RG config).
 *
 * PAYLOAD IS IMMUTABLE once written (no UPDATE grant on it): FR-04's rollback
 * correctness depends on old versions never mutating — a rollback REPUBLISHES
 * an older payload as a NEW version row, so history is append-only in
 * substance.
 *
 * TWO UNIQUE INDEXES BESIDE THE PK (amendment flag 2, countersigned WITH its
 * proving assertion): (tenant, kind, key, version) + the partial published
 * arbiter. The ON CONFLICT arbiter law is about upsert paths and NO writer
 * upserts this table — AiGrantPhysicsTest asserts no ON CONFLICT statement
 * ever targets it, and the publish door serializes its race under the
 * RaceLost-sentinel pattern (PublishRaceLost) instead.
 *
 * APP_WRITABLE_COLUMNS: INSERT names every column except created_at (R-19b
 * posture), published_at and published_by (a version is born draft); UPDATE
 * reaches exactly (status, published_at, published_by, updated_at) — the
 * publish/supersede flips. published_at is stamped IN SQL from the statement's
 * own clock_timestamp(), never an app clock (R-19b provenance discipline).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('ai_config_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->text('kind');
            $table->text('key');
            $table->integer('version');
            $table->jsonb('payload');
            $table->text('status')->default('draft');
            $table->timestampTz('published_at', 6)->nullable();
            $table->uuid('published_by')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_config_versions
                ADD CONSTRAINT ai_config_versions_kind_check CHECK (kind IN
                    ('persona', 'thresholds', 'guardrails', 'provider_routing', 'prompt_template', 'rg_classifier')),
                ADD CONSTRAINT ai_config_versions_status_check CHECK (status IN ('draft', 'published', 'superseded'))
            SQL);

        DB::statement('CREATE UNIQUE INDEX ai_config_versions_identity ON ai_config_versions (tenant_id, kind, key, version)');
        // Exactly ONE published version per config — the FR-04 pointer as physics.
        DB::statement("CREATE UNIQUE INDEX ai_config_versions_published ON ai_config_versions (tenant_id, kind, key) WHERE status = 'published'");

        DB::statement('ALTER TABLE ai_config_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_config_versions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON ai_config_versions
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON ai_config_versions FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, kind, key, version, payload, status, created_by, updated_at) ON ai_config_versions TO app');
        DB::statement('GRANT UPDATE (status, published_at, published_by, updated_at) ON ai_config_versions TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_config_versions');
    }
};
