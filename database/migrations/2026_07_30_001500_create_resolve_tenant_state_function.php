<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A pre-context tenant-state lookup (Doc 01 FR-03, FR-05, SEC-01).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * THE SAME CHICKEN-AND-EGG AS tenant_keys, FOUND LATER AND THE SAME ANSWER.
 *
 * Resolution must know a tenant's STATE, because FR-05 decides whether the surface is
 * permitted at all and AC-08 tests exactly that. But `tenants` carries an RLS policy keyed
 * on `id` — deliberately, so one tenant cannot observe another's existence (SEC-06) — and
 * resolution runs BEFORE any context is bound. So a direct `SELECT state FROM tenants`
 * during resolution fails with:
 *
 *     ERROR: invalid input syntax for type uuid: ""
 *
 * …because the policy evaluates against an empty `app.tenant_id`. Which is the RLS layer
 * working exactly as designed, refusing an unscoped read.
 *
 * HOW THIS HID FOR A WHILE, and why that is the more useful lesson. The key-bearing paths
 * were fine: resolve_tenant_key() already returns tenant_state in the same round trip. The
 * STAFF path used a direct read, and its tests passed anyway — because the fixtures had
 * bound a tenant earlier and left `app.tenant_id` populated, so the read succeeded on
 * leftover state. The AC-08 staff cells were therefore green for the wrong reason. It only
 * surfaced when a different test's fixture cleared the variable properly.
 *
 * Two things follow. First: a test that depends on ambient database session state can pass
 * while the production path is broken. Second: EVERY read that happens during resolution
 * has to go through a narrow SECURITY DEFINER function, not just the one where it was
 * noticed first.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * Owned by `key_resolver` (NOLOGIN, BYPASSRLS) for the same reasons set out in
 * 01-roles.sql: the bypass is scoped to a function body rather than granted to a principal.
 * The function returns one column for one id — it is not a window onto the tenants table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_tenant_state(uuid);');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION resolve_tenant_state(p_tenant_id uuid)
            RETURNS text
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT t.state FROM tenants t WHERE t.id = p_tenant_id;
            $$;
        SQL);

        DB::statement('ALTER FUNCTION resolve_tenant_state(uuid) OWNER TO key_resolver');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_tenant_state(uuid) TO app');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_tenant_state(uuid);');
    }
};
