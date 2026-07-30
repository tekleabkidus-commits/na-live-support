<?php

declare(strict_types=1);

use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| RefreshDatabase is deliberately absent — see tests/TestCase.php. The schema is
| built once per process by the migrator role and each test runs in a rolled-back
| transaction, because the `app` role holds no CREATE privilege and must not.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Contract', 'Integration', 'Isolation', 'Security', 'Property');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
|
| Creating a tenant is not a plain insert, and the reason is worth understanding
| because it is load-bearing for the whole isolation design.
|
| `tenants` carries an RLS policy keyed on `id`, so an INSERT is only permitted
| when `app.tenant_id` already equals the id being inserted. That sounds circular
| but it is exactly right: it means creating a tenant needs NO RLS-bypassing role.
| Provisioning generates the id, binds the context to it, and writes — so the
| single most privileged operation in the platform runs as the ordinary `app` role,
| and platform_admin stays reserved for genuine cross-tenant reads (SEC-02).
|
| These helpers do the same thing production does, in the same order.
|
*/

/**
 * Create a tenant and leave the context bound to it.
 *
 * @param  array<string, mixed>  $attributes
 */
function createTenant(array $attributes = []): Tenant
{
    $tenantId = (string) Str::uuid7();
    $context = app(TenantContext::class);
    $rls = app(RlsSession::class);

    // Bind first: both layers must agree before the row can exist.
    $context->clear();
    $context->bind($tenantId, ResolutionSource::Console);
    $rls->set($tenantId);

    return Tenant::factory()->create(['id' => $tenantId] + $attributes);
}

/**
 * Bind an existing tenant for the remainder of the test.
 *
 * Sets BOTH layers, which is the point: a helper that set only the context would
 * let a test pass while RLS was misconfigured, and a helper that set only the
 * session variable would let one pass while the Eloquent scope was broken. The
 * isolation suite disables one at a time on purpose — everything else binds both.
 */
function bindTenant(Tenant|string $tenant, ResolutionSource $source = ResolutionSource::StaffSession): string
{
    $tenantId = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;

    $context = app(TenantContext::class);
    $context->clear();
    $context->bind($tenantId, $source);
    app(RlsSession::class)->set($tenantId);

    return $tenantId;
}

/**
 * Run a callback as a given tenant, restoring the previous binding afterwards.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function asTenant(Tenant|string $tenant, callable $callback): mixed
{
    $tenantId = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;

    $context = app(TenantContext::class);
    $rls = app(RlsSession::class);

    $previous = $context->tenantIdOrNull();

    $context->clear();
    $context->bind($tenantId, ResolutionSource::Console);
    $rls->set($tenantId);

    try {
        return $callback();
    } finally {
        $context->clear();

        if ($previous !== null) {
            $context->bind($previous, ResolutionSource::Console);
            $rls->set($previous);
        } else {
            $rls->clear();
        }
    }
}

/**
 * Move a tenant to `active` through the real lifecycle service.
 *
 * Not a factory state, and not an UPDATE. The database trigger refuses any state
 * change that does not carry the lifecycle service's transaction-local flag, so a
 * shortcut here would need to defeat a production control — and a fixture that can
 * do what production cannot is a fixture that proves the wrong thing.
 */
function activateTenant(Tenant $tenant): Tenant
{
    return asTenant($tenant, fn (): Tenant => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Active,
        actor: AuditActor::System,
        reason: 'test fixture',
    ));
}

function suspendTenant(Tenant $tenant): Tenant
{
    return asTenant($tenant, fn (): Tenant => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Suspended,
        actor: AuditActor::PlatformAdmin,
        actorLabel: 'test fixture',
        reason: 'test fixture',
    ));
}

/**
 * Run a callback the way a platform worker runs: with NO tenant bound.
 *
 * The outbox relay, retention jobs and quota reconciliation all read across tenants through
 * PlatformConnection, which REFUSES to start while a tenant context is bound (SEC-02).
 * Test fixtures leave a tenant bound — createTenant() binds, and asTenant() restores that
 * binding — so calling a platform operation straight after a fixture trips the guard. That
 * is the guard being right, not a harness quirk: a relay invoked from inside a
 * tenant-resolved request would be exactly the SEC-02 violation it exists to prevent.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function asPlatformWorker(callable $callback): mixed
{
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return $callback();
}

/**
 * Commit the harness transaction so ANOTHER CONNECTION can see the fixtures.
 *
 * Needed by anything that genuinely runs on a second connection in production — the outbox
 * relay, retention, reconciliation — because those go through `pgsql_platform` and an
 * uncommitted transaction on `pgsql` is invisible to it. Faking it (pointing the relay at the
 * test's own connection) would test a code path that does not exist in production and would
 * skip the very cross-tenant read the platform connection is for.
 *
 * The caller must clean up: pass the tenant ids to cleanupTenants() in a finally. Nothing
 * rolls these back.
 */
function commitFixtures(): void
{
    $connection = DB::connection('pgsql');

    if ($connection->transactionLevel() > 0) {
        $connection->commit();
    }
}

/**
 * Delete everything belonging to the given tenants, children before parents.
 *
 * Through the platform connection because the app role holds no DELETE on business tables
 * (BR-02) and RLS would scope the deletes to one tenant at a time anyway.
 *
 * @param  list<string>  $tenantIds
 */
function cleanupTenants(array $tenantIds): void
{
    if ($tenantIds === []) {
        return;
    }

    $platform = DB::connection('pgsql_platform');

    foreach (['events', 'outbox', 'audit_log', 'tenant_settings', 'tenant_sequences',
        'tenant_provisioning_steps', 'tenant_quota_usage', 'tenant_keys', 'users'] as $table) {
        $platform->table($table)->whereIn('tenant_id', $tenantIds)->delete();
    }

    $platform->table('tenants')->whereIn('id', $tenantIds)->delete();
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert a query returns nothing BECAUSE of isolation, not because of an empty
 * database.
 *
 * A test that asserts "zero rows" proves very little on its own — a broken
 * fixture, a typo'd id and working isolation all produce zero. This expectation
 * takes the count a privileged reader sees, so the assertion becomes "the rows
 * exist and this tenant cannot see them", which is the property AC-01 is actually
 * about.
 */
expect()->extend('toBeInvisibleGiven', function (int $rowsThatExist) {
    expect($rowsThatExist)->toBeGreaterThan(
        0,
        'this assertion is vacuous unless the other tenant\'s rows actually exist — check the fixture',
    );

    return $this->toBe(0);
});
