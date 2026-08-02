<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service accounts for machine tokens (Doc 02 FR-19, BR-03; step-9 ruling Q-B).
 *
 * FR-19 binds every machine token to a "service-account user (non-login)". The
 * flag is a TIMESTAMP, not a boolean, for the same reason as every other auth
 * state on `users` (see the Doc 02 schema migration): BR-03 puts these accounts
 * in the audit trail as visible actors, and "since when has this been a service
 * account" is a question an auditor gets to ask.
 *
 * NON-LOGIN IS ENFORCED TWICE (owner ruling, step 9):
 *
 *   1. The password column holds an argon2id hash of 32 random bytes that are
 *      DISCARDED at creation — no password can ever verify against it.
 *   2. `resolve_staff_login()` — replaced below — excludes service accounts at
 *      the SQL layer, so the login path never even receives them as candidates.
 *      A service-account email costs the same two equalised dummy verifications
 *      as a non-existent address (AC-01 is unaffected).
 *
 * Layer 2 lives here rather than in PHP because StaffLoginResolver's contract
 * is "every account matching this address" and silently post-filtering in the
 * provider would make the resolver's answer a lie; the SQL function is where
 * "account that can log in" is defined (it already joins tenant state for the
 * same reason). The provider keeps a belt anyway: session rehydration refuses
 * a service account (StaffUserProvider::retrieveById), so even a session key
 * somehow planted for one cannot produce a principal.
 *
 * Expand-only (C-12): one nullable column, no backfill needed, nothing dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('service_account_at')->nullable();
        });

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
                WHERE u.email = lower(p_email)
                  -- FR-19: service accounts are non-login BY THE DEFINITION OF
                  -- THIS FUNCTION, not by a check someone has to remember.
                  AND u.service_account_at IS NULL;
            $$;
        SQL);

        DB::statement('ALTER FUNCTION resolve_staff_login(text) OWNER TO key_resolver');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_staff_login(text) TO app');
    }

    public function down(): void
    {
        // Restore the pre-service-account function body (the 010600 migration's).
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
                    (u.two_factor_confirmed_at IS NOT NULL) AS two_factor_enabled,
                    (u.password_set_at IS NOT NULL) AS password_set,
                    u.locale,
                    t.state
                FROM users u
                JOIN tenants t ON t.id = u.tenant_id
                WHERE u.email = lower(p_email);
            $$;
        SQL);

        DB::statement('ALTER FUNCTION resolve_staff_login(text) OWNER TO key_resolver');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_staff_login(text) TO app');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('service_account_at');
        });
    }
};
