<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Doc 08 identity SEAM's storage (Doc 04 FR-10 step 3, out-of-scope rule).
 *
 * Doc 04 calls the identity resolver as a SEAM: match-or-create a SHELL customer per
 * {channel, external user}. This migration ships the minimal shape that seam needs and
 * NOTHING more — Doc 08 owns merge rules, shell-customer semantics beyond
 * match-or-create, profile fields, and any extension of these tables (pre-agreed
 * bucket; see App\Domain\Conversations\Identity\CustomerResolver's docblock).
 *
 * Invariant N-06: every field here is staff-collected or channel-reported. There is NO
 * `verified` column and never will be; Doc 01's GrantPhysicsTest schema sweeps pick
 * these tables up automatically, and Doc 08 inherits the no-verified-concept test.
 *
 * customer_identities is the match key: one row per {tenant, channel, external user},
 * many identities may point at one customer (that is what Doc 08's MERGE will do —
 * the shape allows it, this session never does it).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            $table->text('display_name')->nullable();
            $table->text('username')->nullable();
            $table->text('phone')->nullable();

            // TRUE while the row exists only because a channel message arrived and
            // nothing richer is known (FR-10 step 3's "existing or shell").
            $table->boolean('is_shell')->default(true);

            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement('CREATE INDEX customers_tenant_id_index ON customers (tenant_id)');

        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->uuid('customer_id');
            $table->text('channel');
            $table->text('external_user_id');

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customer_identities_match_unique
                ON customer_identities (tenant_id, channel, external_user_id)
            SQL);
        DB::statement('CREATE INDEX customer_identities_customer_index ON customer_identities (tenant_id, customer_id)');

        foreach (['customers', 'customer_identities'] as $tableName) {
            // ADR 0003 physics (Doc 01 BR-02): nothing hard-deletes at tenant scope.
            DB::statement("REVOKE DELETE ON {$tableName} FROM app");

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
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('customers');
    }
};
