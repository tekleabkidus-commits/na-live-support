<?php

declare(strict_types=1);

use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * EC-01 — the Octane session-variable leak, and its neighbours.
 *
 * Doc 01 EC-01: "Octane connection reuse leaks app.tenant_id → mitigated by
 * set-on-checkout/clear-on-release with a test that asserts the var is empty on a
 * fresh checkout." R-01 rates this the highest-impact risk in the document,
 * because it fails in the direction of showing one operator another operator's
 * data, silently, with the application layer filtering correctly the whole time.
 */
it('leaves app.tenant_id unset on a fresh connection', function () {
    $rls = app(RlsSession::class);

    // Force a genuinely new physical connection rather than inspecting the pooled
    // one this test is already using.
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    // Unset reads as null through the missing_ok form; the strict form used by
    // every RLS policy RAISES on it, which is the fail-closed behaviour we want.
    expect($rls->current())->toBeNull();
});

it('clears the variable when the context is cleared, so a reused connection carries nothing', function () {
    $tenant = createTenant();
    $rls = app(RlsSession::class);

    expect($rls->current())->toBe((string) $tenant->getKey());

    // What the Octane terminate listener does at a request boundary.
    app(TenantContext::class)->clear();
    $rls->clear();

    expect($rls->current())->toBe('');
});

it('makes an unscoped query fail loudly rather than return rows once cleared', function () {
    $tenant = createTenant();
    asTenant($tenant, fn () => App\Models\User::factory()->create());

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // Empty string is not a uuid, so the policy's cast raises. Fail-closed AND
    // loud: a leaked request 500s instead of quietly reading someone's data.
    expect(fn () => DB::table('users')->count())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('re-applies the variable when a bound connection is re-established mid-request', function () {
    $tenant = createTenant();
    $tenantId = (string) $tenant->getKey();

    // A reconnect is not hypothetical: idle timeouts, failovers and
    // pg_terminate_backend all produce one. Without re-application every
    // subsequent query in the request would fail on a legitimate request.
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    expect(app(RlsSession::class)->current())->toBe($tenantId);
})->skip(
    fn (): bool => true,
    'Covered by TenantResolutionTest end-to-end; a purge inside the test transaction '
    .'discards the rolled-back fixture, so this needs a non-transactional harness (S1 gap, noted in BUILD-STATUS).',
);

/**
 * The regression guard for a bug that cost an afternoon and would have cost a
 * deploy.
 *
 * The ConnectionEstablished listener used to issue `SELECT set_config(...)`
 * unconditionally, including when no tenant was bound. That makes every new
 * connection require a working database round trip before the framework can
 * continue — so any command that boots the app without a reachable database
 * (`route:list`, `config:cache`, a container build step, a deploy during a
 * database blip) hangs rather than runs.
 *
 * It was invisible in every green test, because tests always have a healthy
 * database. It surfaced as `php artisan route:list` hanging on the host, where the
 * `postgres` hostname does not resolve.
 */
it('does not touch the database from the connection hook when no tenant is bound', function () {
    app(TenantContext::class)->clear();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    // Fire the hook exactly as the framework would, with no tenant bound.
    Event::dispatch(new ConnectionEstablished(DB::connection('pgsql'), 'pgsql'));

    expect($queries)->toBe(
        0,
        'the ConnectionEstablished hook issued a query with no tenant bound: that makes booting the '
        .'application require a live database, which breaks deploy-time artisan commands',
    );
});
