<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook second-credential storage — the Doc 02-owned MINIMAL shape
 * (Doc 02 FR-23, FR-24, AC-07; step-11 plan approval).
 *
 * The widgets-table precedent, applied to the two webhook surfaces: the route
 * key (tenant_keys, hashed, resolved through the SECURITY DEFINER door)
 * answers "which tenant"; THIS row answers "does the request carry the second
 * secret that proves it is really Telegram / really the SMS gateway". By the
 * time it is read, the tenant is bound, so plain RLS-governed reads are
 * correct — no new door.
 *
 * TWO MECHANISM COLUMNS, one row per key, both nullable:
 *
 *   header_secret_hash  FR-23's shape: a bearer-style secret presented in a
 *                       header and compared for equality — so it is stored as
 *                       SHA-256 only, like every other equality credential
 *                       (SEC-03's argument in the tenant_keys migration:
 *                       high-entropy random needs no slow hash, and NFR-02's
 *                       budget forbids one).
 *   hmac_secret         FR-24's shape: the server must COMPUTE an HMAC over
 *                       the raw body, so the plaintext must be recoverable.
 *                       Stored via Laravel's `encrypted` cast (APP_KEY
 *                       ciphertext at rest) — the SAME posture as the TOTP
 *                       secret (users.two_factor_secret), so precedent, not
 *                       novelty (step-11 ruling, decision 5). C-13's vault is
 *                       Doc 18's; BUILD-STATUS carries the migration
 *                       obligation for BOTH encrypted secrets in one entry.
 *
 * A key with NO row here — or a row missing the mechanism its route demands —
 * refuses (fail-closed, AC-07's 403). Doc 05's bot registration and Doc 14's
 * gateway setup are the production write paths; until then rows come from
 * fixtures and console only, and any environment exercising the ingress
 * probes needs seeded credentials or its probes 403 BY DESIGN (recorded in
 * BUILD-STATUS per ruling, decision 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('webhook_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02

            // The tenant_keys row this second credential belongs to. One row
            // per key: a bot has one webhook secret, a gateway one signing
            // secret; rotation is an UPDATE under Doc 05/14's flows.
            $table->uuid('key_id');

            $table->char('header_secret_hash', 64)->nullable();

            // Encrypted cast ciphertext — column is text because the
            // ciphertext length varies with the payload.
            $table->text('hmac_secret')->nullable();

            $table->timestampsTz();             // C-03

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('key_id')->references('id')->on('tenant_keys');
        });

        DB::statement('CREATE UNIQUE INDEX webhook_credentials_key_id_unique ON webhook_credentials (key_id)');
        DB::statement('CREATE INDEX webhook_credentials_tenant_id_index ON webhook_credentials (tenant_id)');

        // Same physics as every Doc 02 tenant table (Doc 01 inherited task #6).
        DB::statement('REVOKE DELETE ON webhook_credentials FROM app');

        DB::statement('ALTER TABLE webhook_credentials ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE webhook_credentials FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON webhook_credentials
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_credentials');
    }
};
