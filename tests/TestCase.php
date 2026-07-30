<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Base test case for the Postgres-backed suite (owner ruling D-4).
 *
 * WHY NOT RefreshDatabase. That trait runs `migrate:fresh` on the DEFAULT
 * connection, and the default connection is the `app` role, which by design holds
 * no CREATE privilege on the schema (ADR 0003). So the standard Laravel harness
 * cannot build this schema — and that is a feature, not a problem to work around:
 * if `app` could migrate, it could also drop a policy.
 *
 * So the schema is built ONCE per process by the migrator role, and each test runs
 * inside a transaction on the app connection that is rolled back afterwards. Fast
 * (one migration per run, not per test) and it exercises the production privilege
 * split rather than a relaxed test-only version of it.
 *
 * THE TEARDOWN IS AN ISOLATION CONTROL, NOT TIDINESS. `app.tenant_id` is set at
 * SESSION level (see RlsSession on why it cannot be transaction level), so a
 * rollback does not clear it. Left set, the next test in the process would start
 * with a previous test's tenant visible at the database — a leak between tests
 * that would make the isolation suite itself unreliable, which is the one thing
 * worse than not having it. It is the same failure as R-01, and it gets the same
 * broom.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Migrations run once per PHP process, not once per test.
     */
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateOnce();

        $this->failFastInsteadOfHanging();

        DB::connection('pgsql')->beginTransaction();
    }

    /**
     * A test may fail. A test may not hang.
     *
     * WHY THIS EXISTS. An isolation test tried `ALTER TABLE users DISABLE ROW LEVEL
     * SECURITY` to prove the layers were independent. That needs ACCESS EXCLUSIVE,
     * which conflicts with the row locks the test's own INSERTs held — so the ALTER
     * waited on the test's transaction while the test waited on the ALTER. Pest was
     * then killed by an external timeout, which left the backend "idle in
     * transaction" with its locks still held AND the queued ALTER still pending. A
     * pending ACCESS EXCLUSIVE request blocks every lock request behind it, so from
     * that moment EVERY query against `users` hung — including the next three
     * unrelated test runs, which each appeared to hang for their own reasons.
     *
     * One deadlock cost three misdiagnoses. Timeouts turn that whole class of
     * incident into an immediate, named failure:
     *
     *   lock_timeout       a statement that cannot get its lock in 5 s fails, rather
     *                      than queueing and taking the suite with it.
     *   statement_timeout  a runaway query dies at 30 s instead of running until CI
     *                      gives up with no useful output.
     *   idle_in_transaction_session_timeout
     *                      a killed test process cannot leave locks held forever;
     *                      Postgres reaps the abandoned transaction after 60 s.
     *
     * Session-level, so they apply to everything the test does, including DDL issued
     * on this connection.
     */
    private function failFastInsteadOfHanging(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement("SET lock_timeout = '5s'");
        $connection->statement("SET statement_timeout = '30s'");
        $connection->statement("SET idle_in_transaction_session_timeout = '60s'");
    }

    protected function tearDown(): void
    {
        $connection = DB::connection('pgsql');

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        // Scrub the RLS session variable and the in-memory context — see the
        // class docblock.
        app(RlsSession::class)->clear($connection);
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function migrateOnce(): void
    {
        if (self::$schemaReady) {
            return;
        }

        // Guard against the classic disaster: a misconfigured environment pointing
        // the suite at the DEVELOPMENT database, where migrate:fresh would drop
        // every table. Cheap check, unbounded downside if absent.
        $database = (string) config('database.connections.pgsql.database');
        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException(sprintf(
                'Refusing to run the suite against database "%s": the name must end in "_test". '
                .'migrate:fresh drops every table, and this guard is all that stands between a '
                .'mistyped env var and the development data.',
                $database,
            ));
        }

        Artisan::call('migrate:fresh', [
            '--database' => 'pgsql_migrator',
            '--force' => true,
        ]);

        self::$schemaReady = true;
    }
}
