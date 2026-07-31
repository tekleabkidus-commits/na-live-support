<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pre-tenant email lookup (Doc 01's inherited constraint #2; Doc 02 FR-01,
 * SEC-01; owner ruling Q1).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE CONSTRAINT, RESTATED FROM User.php's DOCBLOCK.
 *
 * Authenticating by email happens BEFORE any tenant is resolved — that is what
 * authentication is. So the lookup cannot go through the model's global scope,
 * and under FORCE ROW LEVEL SECURITY it cannot go through a direct SELECT either:
 * `users` is policed on tenant_id, and app.tenant_id is unset at that moment.
 *
 * Doc 01 stated the answer's SHAPE and forbade the wrong one: a narrowly-scoped
 * lookup that hands the caller nothing general — never removing or bypassing the
 * scope. This is that lookup, and it is the third instance of the same pattern
 * (resolve_tenant_key, resolve_tenant_state, this), which is the point: the
 * pattern is the platform's answer to "must read before tenancy is known".
 *
 * THIS IS PERMANENT DESIGN, NOT A STOPGAP (owner ruling Q1). Emails are unique
 * per (tenant_id, email), so one address may legitimately exist in several
 * tenants, and Doc 16 §19 lists per-tenant staff domains as FUTURE expansion —
 * so multi-candidate email is the v1 steady state, not a transitional wrinkle.
 * The function therefore returns a SET, and the caller disambiguates by verifying
 * the password against each candidate.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *
 *   - It does not verify the password. Verification is argon2id in PHP, where the
 *     timing-equalisation SEC-01/AC-01 require can be controlled; a SQL-side
 *     comparison would leak through query duration and could not be equalised.
 *   - It does not pattern-match. Exact, lowercase-normalised email only — no
 *     LIKE, no prefix search, so it can never enumerate an address space.
 *   - It returns no password RESET material and no 2FA secret. The hash it does
 *     return is the argon2id digest, which the verifier needs; every other
 *     credential stays behind the RLS boundary until a tenant is bound.
 *   - It answers identically for "no such user" and "user exists": zero rows and
 *     a set of rows are distinguished only by the caller, which is what lets the
 *     caller equalise both the body and the timing (AC-01).
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_staff_login(text);');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION resolve_staff_login(p_email text)
            RETURNS TABLE (
                user_id uuid,
                tenant_id uuid,
                password_hash text,
                disabled boolean,
                locked_until timestamptz,
                failed_login_attempts smallint,
                first_failed_login_at timestamptz,
                two_factor_enabled boolean,
                password_set boolean,
                locale text,
                tenant_state text
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT
                    u.id,
                    u.tenant_id,
                    u.password,
                    (u.disabled_at IS NOT NULL) AS disabled,
                    u.locked_until,
                    u.failed_login_attempts,
                    u.first_failed_login_at,
                    -- ENROLMENT COMPLETE, not merely started: a half-enrolled
                    -- secret must not gate login, or a user who closed the wizard
                    -- would be locked out by a challenge they cannot answer.
                    (u.two_factor_confirmed_at IS NOT NULL) AS two_factor_enabled,
                    (u.password_set_at IS NOT NULL) AS password_set,
                    u.locale,
                    t.state
                FROM users u
                JOIN tenants t ON t.id = u.tenant_id
                WHERE u.email = lower(p_email);
            $$;
        SQL);

        // The bypass is scoped to this function's BODY, not granted to a
        // principal: key_resolver is NOLOGIN, owns only these lookup functions,
        // and appears in no environment variable (ADR 0003).
        DB::statement('ALTER FUNCTION resolve_staff_login(text) OWNER TO key_resolver');
        DB::statement('GRANT SELECT ON users TO key_resolver');
        DB::statement('GRANT SELECT ON tenants TO key_resolver');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_staff_login(text) TO app');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_staff_login(text);');
    }
};
