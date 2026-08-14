<?php

declare(strict_types=1);

use App\Domain\Customers\Identity\LegacyIdentityEnforce;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * S08 SCHEMA AMENDMENT 2 — the ENFORCE release (C-12's final step), shipped
 * in the SAME commit as the resolver rewrite — the writer changed first,
 * which is the whole point of the ordering.
 *
 * Per tenant: LegacyIdentityEnforce (ruling 1's straggler re-run under the
 * same keeper policy + the one-time ADR 0014 phone re-normalization, both
 * refuse-loudly). Then the physics: NOT NULL on customer_identities
 * type/value/source and customers.created_source. From here a legacy-shaped
 * insert is refused by the database, not by convention.
 *
 * A-41 (filed at this step): tenant ENUMERATION here rides pgsql_platform —
 * a SCOPED interpretation (one-time, migration-local, actor logged below),
 * NOT a precedent for broader platform-role use.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = $this->getConnection() ?? config('database.default');

        // A-41's actor log: who used the platform role, for what, and how narrowly.
        Log::info('s08.enforce: tenant enumeration via platform role', [
            'actor' => 'migration:2026_08_14_150000_s08_customer_identities_enforce',
            'scope' => 'A-41 — one-time migration-local tenant enumeration only',
        ]);

        $tenants = DB::connection('pgsql_platform')->table('tenants')->pluck('id');
        $enforce = new LegacyIdentityEnforce;

        foreach ($tenants as $tenantId) {
            DB::connection($connection)->statement(
                "SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]
            );
            $enforce->run($connection);
        }

        DB::connection($connection)->statement(
            "SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)"
        );

        DB::statement('ALTER TABLE customer_identities ALTER COLUMN type SET NOT NULL');
        DB::statement('ALTER TABLE customer_identities ALTER COLUMN value SET NOT NULL');
        DB::statement('ALTER TABLE customer_identities ALTER COLUMN source SET NOT NULL');
        DB::statement('ALTER TABLE customers ALTER COLUMN created_source SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers ALTER COLUMN created_source DROP NOT NULL');
        DB::statement('ALTER TABLE customer_identities ALTER COLUMN source DROP NOT NULL');
        DB::statement('ALTER TABLE customer_identities ALTER COLUMN value DROP NOT NULL');
        DB::statement('ALTER TABLE customer_identities ALTER COLUMN type DROP NOT NULL');
    }
};
