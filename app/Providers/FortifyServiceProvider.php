<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\Login\StaffLoginResolver;
use App\Domain\Auth\Login\StaffUserProvider;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRegistry;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiter as RateLimiterContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify drives the auth flows (Doc 02 T-01) — but never registers a route.
 *
 * Doc 01 turned Fortify's auto-registered routes off because eighteen mutating,
 * unspecified, unaudited endpoints were live (see BUILD-STATUS, inherited task 1).
 * Doc 02's resolution is PERMANENT explicit registration, not re-enabling:
 * `ignoreRoutes()` stays, and routes/auth.php registers each surface by hand as its
 * flow is built and proven — carrying the exact middleware order T-02 specifies
 * (tenant-resolve → guard → tenant-state → 2FA-enforcement → RBAC), its audit
 * declaration, and its throttle. Auto-registration can express none of that, and a
 * route that exists before its flow is specified is the original defect again.
 *
 * What Fortify still owns: the action pipeline (authentication, 2FA challenge,
 * password reset broker), feature flags in config/fortify.php, and the event
 * surface T-01 maps onto §4.8's auth.* events. Views, the custom authentication
 * pipeline, and the 2FA storage overrides are added by their own Doc 02 steps.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        $this->registerStaffUserProvider();
        $this->registerAuthRateLimiter();
    }

    /**
     * FR-03's independent per-IP throttle, applied BEFORE any expensive work
     * (SEC-05's pattern).
     *
     * This is the EC-11 attacker cap: account lockout protects one account and
     * can be weaponised against its owner, so the per-IP limit is what actually
     * bounds a spraying attacker — it is independent of account state, applies
     * to addresses that do not exist, and costs nothing to enforce.
     *
     * O(1) per §12: Laravel's limiter is a Redis counter with a TTL, not a scan.
     *
     * The limit is a PLATFORM setting (`tenant_editable: false`): it protects a
     * shared, pre-tenant surface, and at the moment it is consulted no tenant is
     * known — so reading a per-tenant value here would be impossible as well as
     * wrong.
     */
    private function registerAuthRateLimiter(): void
    {
        // afterResolving, NOT RateLimiter::for() directly — and this is a real
        // bug fix, not a style preference.
        //
        // The facade call resolves the RateLimiter singleton, whose constructor
        // takes `$app['cache']->driver()`. So registering the limiter at boot
        // BUILT THE DEFAULT CACHE STORE during application boot, on every
        // request and in every test. Doc 01's FS-01 proof (QuotaTest) simulates
        // a metering outage by repointing the Redis config and calling
        // `app('redis')->purge('volatile')` — which cannot reach a cache
        // repository that was already constructed with a live connection. The
        // test went from red-when-broken to permanently green: it stopped being
        // able to observe the outage it exists to prove.
        //
        // Deferring to first resolution means nothing touches the cache until a
        // throttled request actually arrives, which is also simply correct —
        // a login limiter has no business opening a Redis connection during
        // `artisan migrate`.
        $this->app->afterResolving(RateLimiterContract::class, function (RateLimiterContract $limiter): void {
            $this->defineAuthIpLimit($limiter);
        });
    }

    private function defineAuthIpLimit(RateLimiterContract $limiter): void
    {
        $limiter->for('auth-ip', function (Request $request): Limit {
            // The REGISTERED DEFAULT, read straight from the registry rather
            // than through SettingsRepository::get(). That method resolves a
            // tenant override and therefore requires a bound tenant — which by
            // construction does not exist here, since this limiter runs before
            // the credential that would identify one has even been read. For a
            // platform-owned key (tenant_editable: false) the registry default
            // IS the value, so nothing is lost.
            $definition = $this->app->make(SettingsRegistry::class)
                ->definition('auth.throttle.ip_per_minute');
            $default = $definition['default'];
            $perMinute = is_numeric($default) ? (int) $default : 20;

            return Limit::perMinute($perMinute)
                ->by((string) $request->ip())
                ->response(fn (): Response => ProblemDetails::response(
                    code: ErrorCode::RateLimited,
                    status: 429,
                    detail: __('auth.throttle.too_many_requests'),
                ));
        });
    }

    /**
     * The `staff` auth driver (Doc 02 FR-01; Doc 01 inherited constraint #2).
     *
     * Registered as a DRIVER rather than bound as a singleton because Laravel's
     * auth manager instantiates providers per guard from config/auth.php, and a
     * guard pointing at a driver nobody registered fails loudly at boot — which
     * is the failure mode to want. A silently-defaulting eloquent provider would
     * instead work in every test that has a tenant bound and break only on the
     * real login path, where no tenant exists.
     */
    private function registerStaffUserProvider(): void
    {
        Auth::provider('staff', fn (Application $app): StaffUserProvider => new StaffUserProvider(
            $app->make(StaffLoginResolver::class),
            $app->make('hash'),
            $app->make(TenantContext::class),
            $app->make(RlsSession::class),
        ));
    }
}
