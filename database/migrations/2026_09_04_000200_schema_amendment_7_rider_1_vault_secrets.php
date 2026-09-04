<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 7 — RIDER 1 (filed at S13 step 4, before this file): the
 * platform's secret-material INTERIM — ciphertext under APP_KEY behind a
 * `vault://` reference (the A-19 Telegram-token shape generalized). Doc 18
 * replaces the table with the real vault behind the same reference contract.
 *
 * Grant physics: INSERT (ref, tenant_id, ciphertext) only; UPDATE (retired_at)
 * only — a rotation's predecessor is retired, never rewritten or deleted;
 * created_at app-DENIED (the rotation history's ordering key, DB time).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");
        DB::statement(<<<'SQL'
            CREATE TABLE vault_secrets (
                ref        text           NOT NULL PRIMARY KEY,
                tenant_id  uuid           NOT NULL REFERENCES tenants (id),
                ciphertext text           NOT NULL,
                created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                retired_at timestamptz(6),
                CONSTRAINT vault_secrets_ref_check CHECK (ref ~ '^vault://[a-z0-9_-]+/')
            )
        SQL);
        DB::statement('ALTER TABLE vault_secrets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE vault_secrets FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON vault_secrets USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid)");
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON vault_secrets FROM app');
        DB::statement('GRANT SELECT ON vault_secrets TO app');
        DB::statement('GRANT INSERT (ref, tenant_id, ciphertext) ON vault_secrets TO app');
        DB::statement('GRANT UPDATE (retired_at) ON vault_secrets TO app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS vault_secrets');
    }
};
