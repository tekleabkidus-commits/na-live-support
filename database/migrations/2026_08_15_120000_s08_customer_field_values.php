<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA AMENDMENT 2 — ADDITION 9 (filed in ANSWERS; COUNTERSIGNED with
 * three conditions, all discharged in the filing before this shipped):
 * FR-16's custom-field VALUE storage for entity=customer — definitions are
 * Doc 12's ([D12], the seeded set fixture-fed), the values are Doc 08's.
 *
 * Condition 1 (checked against the frozen files, result in the filing): no
 * betting-field history affordance is drawn for any agent tier, so the edit
 * HISTORY lives in audit_log old/new pairs — this table holds one CURRENT
 * value per (customer, field).
 * Condition 2: `value` is TEXT deliberately (the seeded set is three
 * strings); typed storage is Doc 12's reopener when typed definitions land.
 * Condition 3: DELETE is revoked because NEITHER path needs it — a RETIRED
 * definition's row survives with its last value (EC-09: "the definition
 * went, the fact stayed"), and a CLEARED field keeps its row with value
 * NULL for churn visibility and audit continuity.
 *
 * APP_WRITABLE_COLUMNS (the deliberate split): INSERT may name every column
 * (a row is born whole); UPDATE reaches exactly (value, updated_by,
 * updated_at) — key, customer and tenant never move by app.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('customer_field_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('customer_id');
            $table->text('field_key');
            $table->text('value')->nullable();  // cleared = NULL, row kept
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('updated_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
        });
        DB::statement('CREATE UNIQUE INDEX customer_field_values_unique ON customer_field_values (tenant_id, customer_id, field_key)');
        // SEC-05: the duplicate-player_id COUNT is index-only on this.
        DB::statement('CREATE INDEX customer_field_values_lookup ON customer_field_values (tenant_id, field_key, value)');

        DB::statement('ALTER TABLE customer_field_values ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_field_values FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON customer_field_values
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON customer_field_values FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, customer_id, field_key, value, updated_by, created_at, updated_at) ON customer_field_values TO app');
        DB::statement('GRANT UPDATE (value, updated_by, updated_at) ON customer_field_values TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_field_values');
    }
};
