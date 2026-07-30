<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->deferAuthenticationRoutesToDoc02();
    }

    /**
     * Stop Fortify publishing authentication routes until Doc 02 designs them.
     *
     * Fortify is a dependency from Session 0 and auto-registers eighteen mutating
     * routes on install: /login, /register, /forgot-password, /reset-password,
     * two-factor enable/disable/challenge, passkeys, password confirmation. All
     * eighteen were live in this application, and none of them exists in any
     * specification yet — Doc 02 owns authentication, sessions and security core,
     * and it has not been built.
     *
     * They surfaced when Doc 01's first audit-coverage registry entry flipped that
     * gate from warn to blocking: eighteen state-mutating routes with no audit
     * entry (Doc 17 / invariant N-13). The tempting fix was to list them in
     * ci/audit-exempt-routes.php, and it was the wrong one — that file's own header
     * says the list "should stay very short", and hollowing out a gate with
     * eighteen exemptions on the day it starts blocking is how a gate becomes
     * decoration.
     *
     * Turning them off is also correct on its own merits, independent of the gate.
     * A live POST /register in a multi-tenant support platform, before any document
     * has defined how registration relates to a tenant, is an endpoint that can
     * only behave wrongly. `users.tenant_id` is NOT NULL and unresolved requests
     * bind no tenant, so it would throw MissingTenantContext — the platform's own
     * isolation catching a route that should not have shipped.
     *
     * Doc 02 removes this line and registers what it has specified, with audit
     * coverage and permission mapping. Recorded in docs/BUILD-STATUS.md so it is a
     * scheduled task rather than a discovery.
     */
    private function deferAuthenticationRoutesToDoc02(): void
    {
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
