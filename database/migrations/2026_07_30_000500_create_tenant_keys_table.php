<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The keys five of the six resolution paths present (Doc 01 FR-03, SEC-03).
 *
 * ONE table with a `kind`, not four tables. Doc 05 owns bot records, Doc 13
 * widget records, Doc 14 gateway records — but the *lookup* that turns a
 * presented secret into a tenant id is one mechanism, and duplicating it four
 * times is how four subtly different comparison routines get written. Those
 * documents attach their own records to a row here.
 *
 * `kind` is DATA, never a branch: no domain code may read it and decide
 * (invariant N-20). The caller states which kind it expects and the lookup
 * either matches or does not — see resolve_tenant_key below.
 *
 * SEC-03 storage: ≥128-bit random secret, stored as sha256, never plaintext.
 * `prefix` is a short non-secret lookup handle so the hash comparison examines
 * exactly one row instead of the whole table.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE CHICKEN-AND-EGG, AND WHY THERE IS A SECURITY DEFINER FUNCTION
 *
 * This table is tenant-scoped, so RLS applies to it. But resolution happens
 * BEFORE any tenant is known — that is the entire point of resolution — so a
 * plain `SELECT … FROM tenant_keys` by the app role would be filtered by a
 * policy whose `app.tenant_id` is not yet set, and return nothing. Three ways
 * out, and only one is defensible:
 *
 *   ✗ Exempt tenant_keys from RLS. Punches a hole in "RLS on every tenant
 *     table" (SEC-01) and hands any SQL-injection foothold the whole key table.
 *   ✗ Let the app set a "resolving" escape flag the policy honours. The app
 *     grants itself the bypass, so any leaked code path can too.
 *   ✓ A SECURITY DEFINER function owned by the schema owner, granted EXECUTE to
 *     app, which takes (kind, prefix, hash) and returns ONE row's tenant id and
 *     state. The app role has NO table privileges on tenant_keys at all — see
 *     the RLS migration's REVOKE — so it cannot read, enumerate, or write keys;
 *     it can only ask "does this exact secret belong to a tenant?".
 *
 * The function returns tenant state alongside the id so that suspension
 * (FR-05/AC-08) can be enforced without a second round trip on the ≤1 ms p99
 * resolution budget (NFR-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('kind', 32);
            $table->string('prefix', 16)->unique();   // non-secret lookup handle
            $table->char('hash', 64);                 // sha256 hex of the secret
            $table->string('label');                  // operator-facing name, e.g. "Website widget"
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement("
            ALTER TABLE tenant_keys ADD CONSTRAINT tenant_keys_kind_check
            CHECK (kind IN ('api', 'telegram_bot', 'widget', 'sms_gateway'))
        ");

        DB::statement('CREATE INDEX tenant_keys_tenant_id_index ON tenant_keys (tenant_id)');

        // The only door the application role has onto this table.
        //
        // SECURITY DEFINER runs as the function's owner (the schema owner), so
        // it sees past RLS — deliberately, and for exactly one question. Note
        // `SET search_path = public, pg_temp`: without it, a caller who could
        // create objects in an earlier schema could shadow a name used inside
        // the body. The app role cannot create anything (ADR 0003), but a
        // SECURITY DEFINER function without a pinned search_path is a
        // privilege-escalation pattern and should never be written unpinned.
        //
        // Hash comparison is a plain `=` on a sha256 hex. The prefix has already
        // narrowed this to one row, so there is no enumeration oracle here;
        // timing-safe comparison and per-key rate limiting on the presented
        // secret are Doc 02's (AC-01) to own across all credential types.
        // DROP-then-CREATE, not CREATE OR REPLACE: `migrate:fresh` drops TABLES
        // (and views) but leaves FUNCTIONS behind, so a second run would collide
        // with the survivor from the first. CREATE OR REPLACE would paper over it
        // today and fail the day this function's return type changes, since
        // REPLACE cannot alter a signature. Being explicit costs one line.
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_tenant_key(text, text, text);');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION resolve_tenant_key(p_kind text, p_prefix text, p_hash text)
            RETURNS TABLE (
                key_id uuid,
                tenant_id uuid,
                tenant_state text,
                revoked boolean
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT
                    k.id,
                    k.tenant_id,
                    t.state,
                    (k.revoked_at IS NOT NULL) AS revoked
                FROM tenant_keys k
                JOIN tenants t ON t.id = k.tenant_id
                WHERE k.kind = p_kind
                  AND k.prefix = p_prefix
                  AND k.hash = p_hash;
            $$;
        SQL);

        // OWNERSHIP IS THE SECURITY BOUNDARY HERE, so it is set explicitly.
        //
        // A SECURITY DEFINER function runs as its owner. Owned by `migrator` — the
        // default, since migrations run as migrator — the function was subject to
        // FORCE ROW LEVEL SECURITY like any other owner, so its own query was
        // filtered by `app.tenant_id`: the value it exists to discover. It failed
        // with `invalid input syntax for type uuid: ""` and five of the six FR-03
        // resolution paths could not resolve at all.
        //
        // `key_resolver` is NOLOGIN with BYPASSRLS and owns nothing else. Nobody can
        // connect as it; its bypass is reachable only by invoking this function,
        // whose body reads one row matched on an exact key hash. So the bypass is
        // scoped to a function BODY rather than granted to a principal, and FORCE
        // stays on every tenant table (owner ruling, S1).
        DB::statement('ALTER FUNCTION resolve_tenant_key(text, text, text) OWNER TO key_resolver');

        // Executing as key_resolver, the function needs its own read privileges —
        // default privileges grant to app and platform_admin only. SELECT on exactly
        // the two tables it joins, and nothing more.
        DB::statement('GRANT SELECT ON tenant_keys TO key_resolver');
        DB::statement('GRANT SELECT ON tenants TO key_resolver');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS resolve_tenant_key(text, text, text);');
        Schema::dropIfExists('tenant_keys');
    }
};
