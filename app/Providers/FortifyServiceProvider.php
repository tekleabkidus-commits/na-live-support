<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

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
        // Grows per Doc 02 step: authentication pipeline (login step), view
        // bindings (views step). Kept empty rather than stubbed so nothing
        // half-configured is reachable in the meantime.
    }
}
