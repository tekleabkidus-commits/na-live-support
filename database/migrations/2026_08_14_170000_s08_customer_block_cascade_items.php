<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA AMENDMENT 2 — ADDITION 7 (filed in ANSWERS before this shipped):
 * the customer-block cascade's restore memory. FR-18's unblock returns
 * conversations "to the state they were in" — this table records WHICH
 * conversations the cascade blocked, so individually-blocked conversations
 * survive a customer unblock. Grant physics = the marker pattern's third
 * application (insert-born-unrestored; UPDATE reaches restored_at alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('customer_block_cascade_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('customer_id');
            $table->uuid('conversation_id');
            $table->timestampTz('restored_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
        });
        DB::statement('CREATE UNIQUE INDEX customer_block_cascade_unique ON customer_block_cascade_items (tenant_id, customer_id, conversation_id)');

        DB::statement('ALTER TABLE customer_block_cascade_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customer_block_cascade_items FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON customer_block_cascade_items
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON customer_block_cascade_items FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, customer_id, conversation_id, created_at) ON customer_block_cascade_items TO app');
        DB::statement('GRANT UPDATE (restored_at) ON customer_block_cascade_items TO app');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_block_cascade_items');
    }
};
