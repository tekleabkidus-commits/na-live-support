<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;

/**
 * Wires tenancy into the framework lifecycle (Doc 01 T-05, R-01, EC-01).
 *
 * The interesting work here is all about Octane. In a classic PHP process the
 * process dies at the end of a request and every leak dies with it; under Octane
 * the container, the singletons and the database connections all survive, so
 * "cleanup" stops being hygiene and becomes an isolation control.
 *
 * Three mechanisms, deliberately overlapping:
 *
 *   1. TenantContext is `scoped()`, so Octane discards the instance at each
 *      request boundary. Even if everything below were deleted, a stale tenant id
 *      could not survive in the container.
 *   2. The RLS session variable is cleared on every terminate event — request,
 *      task and tick. The container reset in (1) does NOT clear it, because it
 *      lives in PostgreSQL on a connection Octane keeps open. This is R-01, the
 *      highest-rated risk in Doc 01, and it needs its own broom.
 *   3. On ConnectionEstablished the variable is re-applied if a tenant is
 *      currently bound. Without this a mid-request reconnect (idle timeout,
 *      failover, `pg_terminate_backend`) would drop the variable and every
 *      subsequent query in that request would fail — legitimate traffic dying
 *      because the safety mechanism worked correctly on the wrong occasion.
 *
 * Cleanup is registered for tasks and ticks, not only requests, because Octane
 * concurrent tasks and the tick timer run on the same worker and the same pooled
 * connections. A tick that touched tenant data and did not clean up would hand
 * its tenant to the next unrelated request.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), never singleton() — see (1) above.
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(RlsSession::class);
    }

    public function boot(): void
    {
        $this->reapplyOnReconnect();
        $this->clearOnOctaneTermination();
    }

    /**
     * Mechanism (3): a new physical connection starts with no session variable.
     */
    private function reapplyOnReconnect(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            // Only the tenant-facing connection carries the variable. The
            // migrator and platform connections must NOT be given a tenant id:
            // the platform role bypasses RLS by design, and silently scoping it
            // would break the console in a way that looks like missing data.
            if ($event->connectionName !== 'pgsql') {
                return;
            }

            $context = $this->app->make(TenantContext::class);

            // NOTHING TO DO IF NO TENANT IS BOUND, and doing nothing is important.
            //
            // A brand-new connection has never had `app.tenant_id` set, so there is
            // nothing to clear — the variable is absent, and absent is the
            // fail-closed state: the strict `current_setting()` in every RLS policy
            // raises on it. Clearing it would be writing a value that already means
            // the same thing.
            //
            // The version that did clear it unconditionally cost an afternoon.
            // Issuing a statement from inside ConnectionEstablished makes EVERY new
            // connection require a working database round trip before the framework
            // can proceed — so `php artisan route:list`, `config:cache`, any build
            // or deploy step that boots the app without a reachable database, hangs
            // instead of running. It was found by route:list hanging on the host,
            // where the `postgres` hostname does not resolve, and it would have been
            // found in production by a deploy failing during a database blip.
            //
            // Re-applying for a BOUND tenant is different and stays: there the
            // variable genuinely must be restored, we are already mid-request with a
            // live database, and skipping it would break legitimate traffic after a
            // reconnect.
            if (! $context->isBound()) {
                return;
            }

            // No re-entrancy guard, deliberately. An earlier version carried one,
            // on the theory that issuing a statement from inside this hook could
            // re-enter it via a reconnect. That theory was about the unbound branch,
            // which no longer issues anything — so the guard became code that could
            // not execute, which PHPStan correctly rejected. Reaching here means a
            // connection was just established AND a tenant is bound: the statement
            // runs on the live connection and fires no further event.
            //
            // The residual case — a connection dropping between establishment and
            // this statement, repeatedly — is recorded as a risk in
            // docs/BUILD-STATUS.md rather than defended with unreachable code.
            $this->app->make(RlsSession::class)->set($context->tenantId(), $event->connection);
        });
    }

    /**
     * Mechanism (2): scrub the database session at every Octane boundary.
     */
    private function clearOnOctaneTermination(): void
    {
        if (! class_exists(RequestTerminated::class)) {
            return;
        }

        foreach ([RequestTerminated::class, TaskTerminated::class, TickTerminated::class] as $event) {
            Event::listen($event, function (): void {
                $this->scrub();
            });
        }
    }

    private function scrub(): void
    {
        $context = $this->app->make(TenantContext::class);
        $context->clear();

        // Only touch connections that already exist. Resolving one here just to
        // clear it would open a connection on every idle tick.
        foreach ($this->app->make('db')->getConnections() as $name => $connection) {
            if ($name !== 'pgsql') {
                continue;
            }

            try {
                $this->app->make(RlsSession::class)->clear($connection);
            } catch (\Throwable) {
                // A connection that is already gone needs no clearing, and a
                // failure to scrub must not become the response the user sees.
                // The next ConnectionEstablished will set it correctly.
            }
        }
    }
}
