<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API tokens — personal and machine (Doc 02 FR-18, FR-19, FR-20, T-05, BR-03).
 *
 * WRITTEN HERE RATHER THAN PUBLISHED FROM SANCTUM. Sanctum ships a migration it
 * only publishes on request, and this application has never run it — so nothing
 * created this table and the api guard had no storage. Publishing it verbatim
 * would have violated four conventions at once: `$table->id()` is a bigint
 * (C-01 requires UUIDv7), `timestamp` columns are not timestamptz (C-03), there
 * is no `tenant_id` (C-02), and a table with no RLS policy sits outside Layer 2
 * entirely (N-01) while holding credentials that authenticate API traffic.
 *
 * The column names Sanctum's model expects are preserved exactly — `tokenable_*`,
 * `token`, `abilities`, `last_used_at`, `expires_at` — so PersonalAccessToken
 * keeps working; what changes is everything the platform's own rules govern.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PRE-TENANT BEARER LOOKUP (owner ruling — new plan row).
 *
 * Bearer auth has the same chicken-and-egg as staff login and tenant keys: the
 * request presents a token and NOTHING else, so the row must be found before any
 * tenant can be bound — but the table is FORCE-RLS'd, and exempting it was ruled
 * unacceptable (it holds the credentials that authenticate every API call).
 *
 * So it gets the resolve_tenant_key treatment: one SECURITY DEFINER function,
 * owned by the NOLOGIN key_resolver role, matching an exact token hash and
 * returning only what is needed to bind the tenant and continue under ordinary
 * RLS. `app` gets EXECUTE on the function and keeps NO general SELECT path to
 * other tenants' token rows.
 *
 * Sanctum's own lookup — `PersonalAccessToken::findToken()` — is therefore
 * replaced in the guard (see the token step); this function is the only door.
 * Machine tokens are the same table and the same door: FR-19 binds them to a
 * service-account user, so "which tenant" is answered identically, and BR-03's
 * "always act as their visible service account" falls out of using the same
 * tokenable edge rather than a special system principal.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();                  // C-01 (Sanctum's bigint replaced)
            $table->uuid('tenant_id');                      // C-02

            // Sanctum's polymorphic owner edge, retyped for UUID keys.
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');

            $table->text('name');

            // SHA-256 of the plaintext Sanctum shows exactly once (FR-18).
            $table->string('token', 64)->unique();

            // The token's requested scopes. T-05 intersects these with the
            // owner's LIVE permissions at request time, so what is stored here
            // is a ceiling, never a grant.
            $table->text('abilities')->nullable();

            $table->timestampTz('last_used_at')->nullable(); // C-03, FR-18 surface
            $table->timestampTz('expires_at')->nullable();

            // FR-19's rotation flow: a replacement token overlaps its predecessor,
            // then the old one is revoked. Revocation is a timestamp rather than a
            // delete so the audit trail keeps the token that WAS used (BR-02/BR-03).
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement('CREATE INDEX personal_access_tokens_tokenable_index ON personal_access_tokens (tokenable_type, tokenable_id)');
        // FS-05's nightly prune scans by expiry; partial because the rows that
        // matter are the ones that HAVE an expiry.
        DB::statement('CREATE INDEX personal_access_tokens_expires_at_index ON personal_access_tokens (expires_at) WHERE expires_at IS NOT NULL');

        // BR-02: revocation is a timestamp, not a delete. FS-05's prune runs as
        // platform_admin through PlatformConnection (owner ruling Q4).
        DB::statement('REVOKE DELETE ON personal_access_tokens FROM app');

        DB::statement('ALTER TABLE personal_access_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE personal_access_tokens FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON personal_access_tokens
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        // The pre-tenant door — see the class docblock for why it exists and why
        // an RLS exemption was not an option.
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_api_token(text);');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION resolve_api_token(p_token_hash text)
            RETURNS TABLE (
                token_id uuid,
                tenant_id uuid,
                tokenable_type text,
                tokenable_id uuid,
                abilities text,
                expired boolean,
                revoked boolean,
                user_disabled boolean,
                tenant_state text
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT
                    p.id,
                    p.tenant_id,
                    p.tokenable_type,
                    p.tokenable_id,
                    p.abilities,
                    (p.expires_at IS NOT NULL AND p.expires_at <= now()) AS expired,
                    (p.revoked_at IS NOT NULL) AS revoked,
                    -- BR-04/EC-05: a disabled owner's token dies with the account,
                    -- and the guard learns that in the SAME round trip rather than
                    -- from a second query that could be forgotten.
                    (u.disabled_at IS NOT NULL) AS user_disabled,
                    t.state
                FROM personal_access_tokens p
                JOIN tenants t ON t.id = p.tenant_id
                LEFT JOIN users u ON u.id = p.tokenable_id
                WHERE p.token = p_token_hash;
            $$;
        SQL);

        DB::statement('ALTER FUNCTION resolve_api_token(text) OWNER TO key_resolver');
        DB::statement('GRANT SELECT ON personal_access_tokens TO key_resolver');
        DB::statement('GRANT SELECT ON users TO key_resolver');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_api_token(text) TO app');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_api_token(text);');
        Schema::dropIfExists('personal_access_tokens');
    }
};
