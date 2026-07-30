<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * @audit-change-control: Doc01-N-13 (append-only grants on audit_log)
 *
 * LAYER 2 OF TWO (Doc 01 T-01, NFR-01, SEC-01; invariant N-01). The deliverable.
 *
 * Layer 1 is a global Eloquent scope: it is correct, it is convenient, and it is
 * one forgotten `DB::table()` away from being absent. NFR-01 states the contract
 * plainly — "a defect that omits an application-level tenant filter SHALL still
 * be contained by row-level security". This migration is that containment, and it
 * is deliberately written in a different language, enforced by a different
 * engine, than the code it backstops. Two layers that share an implementation
 * are one layer wearing two hats.
 *
 * WHAT EACH STATEMENT BUYS
 *
 *   ENABLE ROW LEVEL SECURITY   policies start applying to non-owner roles.
 *   FORCE  ROW LEVEL SECURITY   …and to the owner too. Belt and braces: the app
 *                               role is not the owner (ADR 0003), so ENABLE
 *                               already covers it, but FORCE means a future
 *                               migration run by the schema owner cannot read
 *                               across tenants either.
 *   CREATE POLICY               the rule itself, exactly as Doc 01 T-01 specifies:
 *                               USING (tenant_id = current_setting('app.tenant_id')::uuid)
 *
 * ON THE STRICT `current_setting` FORM. Doc 01 T-01 spells it without the
 * missing_ok argument, and that is the right call: with `current_setting(x, true)`
 * an unbound context yields NULL, the comparison yields NULL, and the query
 * quietly returns zero rows — indistinguishable from "this tenant has no data".
 * The strict form raises instead. Unbound access therefore fails LOUDLY at the
 * database, which is what you want from a layer whose whole job is to catch the
 * case where the application layer already failed. Fail-closed either way; loud
 * beats silent.
 *
 * PRIVILEGE NARROWING, DONE HERE BECAUSE IT IS THE SAME SUBJECT
 *
 *   BR-02  nothing is hard-deleted at tenant scope → DELETE is revoked from the
 *          app role on every business table. The exception is tenant_settings,
 *          where "reset to default" (Doc 01 §8) genuinely IS deleting the
 *          override row and falling back to the code-side default.
 *   N-13   the audit log is append-only BY GRANT → UPDATE and DELETE revoked.
 *          Not a trigger, not a convention: a missing privilege.
 *   SEC-03 the app role cannot read key hashes at all. Column-level SELECT lets
 *          an operator list their own keys while `hash` stays unreadable; the
 *          only way to verify a secret is resolve_tenant_key(), which the app may
 *          EXECUTE but whose table it cannot read.
 */
return new class extends Migration
{
    /**
     * Tenant-scoped tables and the column that carries the tenant.
     *
     * `tenants` is keyed on `id`: one tenant may not even observe that another
     * exists, which is what makes SEC-06's 404-not-403 rule true at the database
     * rather than in a controller.
     *
     * This list is canonical but it is NOT the safety net —
     * tests/Isolation/RlsCoverageTest.php derives the truth from the live schema
     * and fails if any table carrying a tenant_id is missing RLS. A future module
     * that adds a table and forgets this file gets a red build, not a silent hole.
     *
     * @var array<string, string>
     */
    private const TENANT_TABLES = [
        'tenants' => 'id',
        'users' => 'tenant_id',
        'tenant_keys' => 'tenant_id',
        'tenant_settings' => 'tenant_id',
        'tenant_sequences' => 'tenant_id',
        'tenant_provisioning_steps' => 'tenant_id',
        'audit_log' => 'tenant_id',
        'outbox' => 'tenant_id',
        'events' => 'tenant_id',
        'tenant_quota_usage' => 'tenant_id',
    ];

    /**
     * Business tables where a hard DELETE by the application is forbidden (BR-02).
     *
     * tenant_settings is absent by design (see the class docblock).
     *
     * @var list<string>
     */
    private const NO_DELETE_TABLES = [
        'tenants',
        'users',
        'tenant_keys',
        'tenant_sequences',
        'tenant_provisioning_steps',
        'audit_log',
        'outbox',
        'events',
        'tenant_quota_usage',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table => $column) {
            $this->protect($table, $column);
        }

        // A partitioned table's parent policies apply to rows reached THROUGH the
        // parent. Query a partition directly and only that partition's own
        // policies apply — so every partition needs its own copy, or `SELECT *
        // FROM events_2026_07` would be an unscoped read of a month of events.
        // app/Support/Events/EventPartitions.php repeats this for partitions
        // created later, and RlsCoverageTest asserts no partition is ever bare.
        foreach ($this->eventPartitions() as $partition) {
            $this->protect($partition, 'tenant_id');
        }

        foreach (self::NO_DELETE_TABLES as $table) {
            DB::statement(sprintf('REVOKE DELETE ON %s FROM app', $table));
        }

        // N-13: append-only physics. UPDATE goes too — an audit trail you can
        // edit is a diary, not a record.
        DB::statement('REVOKE UPDATE ON audit_log FROM app');

        // SEC-03: the app role may write a hash and match a hash, never read one.
        DB::statement('REVOKE ALL ON tenant_keys FROM app');
        DB::statement('GRANT SELECT (id, tenant_id, kind, prefix, label, last_used_at, revoked_at, created_at, updated_at) ON tenant_keys TO app');
        DB::statement('GRANT INSERT (id, tenant_id, kind, prefix, hash, label, last_used_at, revoked_at, created_at, updated_at) ON tenant_keys TO app');
        DB::statement('GRANT UPDATE (label, last_used_at, revoked_at, updated_at) ON tenant_keys TO app');
        DB::statement('GRANT EXECUTE ON FUNCTION resolve_tenant_key(text, text, text) TO app');

        // The platform console and retention jobs reach past RLS by role
        // (platform_admin holds BYPASSRLS) and hold ALL on tables by default
        // privilege. Nothing to grant here; noted so the absence is not read as
        // an oversight. Their use is logged with actor identity by
        // PlatformConnection (SEC-02).
    }

    public function down(): void
    {
        foreach (self::NO_DELETE_TABLES as $table) {
            DB::statement(sprintf('GRANT DELETE ON %s TO app', $table));
        }
        DB::statement('GRANT UPDATE ON audit_log TO app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON tenant_keys TO app');
        DB::statement('REVOKE EXECUTE ON FUNCTION resolve_tenant_key(text, text, text) FROM app');

        foreach ([...array_keys(self::TENANT_TABLES), ...$this->eventPartitions()] as $table) {
            DB::statement(sprintf('DROP POLICY IF EXISTS tenant_isolation ON %s', $table));
            DB::statement(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $table));
            DB::statement(sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $table));
        }
    }

    /**
     * Enable, force, and police one table.
     */
    private function protect(string $table, string $column): void
    {
        DB::statement(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
        DB::statement(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));

        // USING governs which rows are visible; WITH CHECK governs which rows may
        // be written. Both are required: without WITH CHECK, tenant A could
        // INSERT a row stamped with tenant B's id — invisible to A afterwards,
        // and very visible to B.
        DB::statement(sprintf(
            <<<'SQL'
                CREATE POLICY tenant_isolation ON %1$s
                    USING (%2$s = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (%2$s = current_setting('app.tenant_id')::uuid)
                SQL,
            $table,
            $column,
        ));
    }

    /**
     * Existing partitions of `events`, read from the catalog rather than
     * recomputed from dates — the migration that created them is the authority
     * on which months exist, not this one's idea of "now".
     *
     * @return list<string>
     */
    private function eventPartitions(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::select("
            SELECT c.relname
            FROM pg_class c
            JOIN pg_inherits i ON i.inhrelid = c.oid
            JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = 'events'
            ORDER BY c.relname
        ");

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }
};
