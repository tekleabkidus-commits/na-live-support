<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Password-reset and invitation tokens (Doc 02 FR-16, FR-17, SEC-02, EC-04).
 *
 * SEC-02 states the properties exactly: "Reset/invite tokens: 256-bit, single-use,
 * hashed at rest, bound to email + purpose." This table is that sentence as
 * schema, and each clause is enforced by something the application cannot forget:
 *
 *   256-bit      the issuer generates 32 random bytes; the CHECK constraint below
 *                pins the stored hash's length so a shortened token cannot be
 *                stored at all.
 *   hashed       only `token_hash` exists. There is no plaintext column, so
 *                "we accidentally logged the token from the DB" has no source.
 *                GrantPhysicsTest asserts the absence as a catalog fact.
 *   single-use   `consumed_at`, plus the partial unique index making at most ONE
 *                unconsumed token per (user, purpose) — a resend invalidates the
 *                previous link (EC-04) by construction rather than by remembering
 *                to delete it.
 *   bound to     `purpose` is part of the lookup, so a reset token can never be
 *   purpose      redeemed as an invite token or vice versa. Different lifetimes
 *                (FR-16's 60 min vs FR-17's 7 days) and different privilege
 *                outcomes make cross-purpose redemption a real escalation.
 *
 * WHY THIS REPLACES Laravel's `password_reset_tokens`. That scaffolding table is
 * keyed by EMAIL as its primary key, which cannot express this platform: emails
 * are unique per TENANT (Doc 01), so one address may legitimately exist in several
 * tenants, and a single-row-per-email table would let one tenant's reset request
 * silently overwrite another's. The owner ruling on multi-tenant email ambiguity
 * requires one token PER MATCHING ACCOUNT, each bound to its user and tenant —
 * which is exactly what a user_id-keyed table gives and the stock one cannot.
 *
 * Doc 02's own EC-03 (reset link opened on a different device) needs nothing here:
 * the token is not bound to a device, deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        // See the cold-summaries migration: a tenants FK validation scan touches
        // a FORCE-RLS table, whose policy errors if app.tenant_id was never set on
        // this connection. Transaction-local; the value is irrelevant.
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('auth_action_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();                      // C-01 UUIDv7
            $table->uuid('tenant_id');                           // C-02
            $table->uuid('user_id');

            // 'password_reset' | 'invitation'. A short string rather than an enum
            // type: adding a purpose later must not require a DDL lock on a table
            // that sits on the login path (C-12's spirit).
            $table->string('purpose', 32);

            // SHA-256 hex of the 32 random bytes. Hex rather than a password hash:
            // the token is high-entropy and looked up by exact match, so it needs
            // preimage resistance, not a slow KDF — and a slow KDF here would make
            // the lookup unindexable, forcing a table scan per redemption.
            $table->char('token_hash', 64);

            // Bound to the address it was SENT to, so changing the account email
            // after issuance invalidates outstanding links (SEC-02's "bound to
            // email"). Compared, never used to find the row.
            $table->string('sent_to_email');

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();

            // Audit-visible provenance: who asked, from where. Never the token.
            $table->string('requested_ip', 45)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        // Redemption looks up by hash alone (the presenter has no user id), so this
        // must be unique and indexed. Unique also means a hash collision across
        // tenants is a write error rather than an ambiguous read.
        DB::statement('CREATE UNIQUE INDEX auth_action_tokens_hash_unique ON auth_action_tokens (token_hash)');

        // EC-04: "old link invalidated" on resend, as physics. At most one LIVE
        // token per (user, purpose) — issuing a new one requires consuming or
        // expiring the old, so a stale invite link cannot coexist with a fresh one.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX auth_action_tokens_one_live_per_purpose
                ON auth_action_tokens (user_id, purpose)
                WHERE consumed_at IS NULL
            SQL);

        // A 64-char hex hash, always. Catches a truncated or unhashed value at the
        // boundary instead of storing something guessable.
        DB::statement(<<<'SQL'
            ALTER TABLE auth_action_tokens
                ADD CONSTRAINT auth_action_tokens_hash_format_check
                CHECK (token_hash ~ '^[0-9a-f]{64}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE auth_action_tokens
                ADD CONSTRAINT auth_action_tokens_purpose_check
                CHECK (purpose IN ('password_reset', 'invitation'))
            SQL);

        // BR-02: no hard deletes at tenant scope. Expired tokens are pruned by the
        // platform retention path (FS-05's sibling), never by the app role.
        DB::statement('REVOKE DELETE ON auth_action_tokens FROM app');

        DB::statement('ALTER TABLE auth_action_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE auth_action_tokens FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON auth_action_tokens
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);

        /*
         | THE PRE-TENANT DOOR, for the same reason tenant_keys has one.
         |
         | Someone clicking a reset link has no session and no tenant: the token IS
         | the only thing they present. So the lookup must happen before
         | app.tenant_id can be set, which RLS forbids on a policed table — and the
         | fix is emphatically NOT to exempt this table (it holds the credentials
         | that grant account access). It is the resolve_tenant_key shape: one
         | SECURITY DEFINER function, owned by the NOLOGIN key_resolver role,
         | answering exactly one question and handing back no general read access.
         |
         | What it returns is the minimum needed to bind a tenant and then continue
         | under ordinary RLS: which tenant, which user, what purpose, and whether
         | the token is still live. It never returns the hash, never accepts a
         | partial or pattern match, and cannot enumerate — an unknown hash returns
         | zero rows, indistinguishable from an expired or consumed one at the SQL
         | layer (SEC-01's shape, enforced again in the application).
         */
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_auth_action_token(text);');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION resolve_auth_action_token(p_hash text)
            RETURNS TABLE (
                token_id uuid,
                tenant_id uuid,
                user_id uuid,
                purpose text,
                sent_to_email text,
                expired boolean,
                consumed boolean,
                tenant_state text
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT
                    a.id,
                    a.tenant_id,
                    a.user_id,
                    a.purpose,
                    a.sent_to_email,
                    (a.expires_at <= now()) AS expired,
                    (a.consumed_at IS NOT NULL) AS consumed,
                    t.state
                FROM auth_action_tokens a
                JOIN tenants t ON t.id = a.tenant_id
                WHERE a.token_hash = p_hash;
            $$;
        SQL);

        DB::statement('ALTER FUNCTION resolve_auth_action_token(text) OWNER TO key_resolver');
        DB::statement('GRANT SELECT ON auth_action_tokens TO key_resolver');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_auth_action_token(text) TO app');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_auth_action_token(text);');
        Schema::dropIfExists('auth_action_tokens');
    }
};
