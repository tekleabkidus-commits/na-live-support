<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Doc 01 T-05's HTTP binding, and the FR-05 state matrix. Both take a
        // parameter naming which of the six resolution paths / three surfaces the
        // route belongs to, so the route declares its own trust model and no
        // middleware has to guess from the URL.
        $middleware->alias([
            'tenant' => App\Http\Middleware\ResolveTenant::class,
            // Doc 02's bearer surface (FR-18/19/20): token → tenant through the
            // resolve_api_token door. A separate binder rather than a branch in
            // ResolveTenant so each surface still trusts exactly ONE credential
            // kind — see the middleware's docblock.
            'tenant.bearer' => App\Http\Middleware\ResolveBearerTenant::class,
            'tenant.state' => App\Http\Middleware\EnforceTenantState::class,
            // Declares which audit action a mutating route emits, and verifies at runtime
            // that it actually did. The `audit-coverage` gate reads these declarations.
            'audit' => App\Http\Middleware\DeclareAuditAction::class,
            // Doc 02 T-02/T-05: declares which permission a route requires. The
            // permission-mapping gate reads these; enforcement layers attach here
            // (token-scope intersection in Doc 02's token step, staff verdicts in
            // Doc 03). See the middleware's docblock before assuming it enforces.
            'permission' => App\Http\Middleware\RequirePermission::class,
        ]);

        // T-02's bearer order, enforced against the middleware SORTER, not just
        // the route declaration. The route lists tenant.bearer before auth:api,
        // but Laravel re-sorts any route stack containing priority-listed
        // middleware — and Authenticate is priority-listed above the api
        // group's SubstituteBindings, so it HOPPED over the (unlisted) bearer
        // binder and the Sanctum guard ran with no tenant bound: every bearer
        // request 500'd with MissingTenantContext. Registering the binder
        // immediately BEFORE Authenticate in the priority list makes the
        // declared order and the executed order the same thing. The
        // MiddlewareOrderTest asserts the EXECUTED order for exactly this
        // reason.
        // The CONTRACT, not the concrete class: the kernel's priority list
        // names AuthenticatesRequests, and an entry that is not literally in
        // the list is silently appended at the END — which put the binder
        // AFTER the guard and reproduced the exact bug this exists to fix.
        $middleware->prependToPriorityList(
            before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: App\Http\Middleware\ResolveBearerTenant::class,
        );

        // The widget stack needs the SAME protection (Doc 02 step 10): its
        // routes declare tenant:widget_key → WidgetCors → auth:widget, and
        // without these entries the priority-listed Authenticate hops over
        // both unlisted predecessors — the guard would run with no tenant
        // bound (the step-9 bug, on a new surface) and its 401s would leave
        // unstamped, unreadable to the allowed page whose recovery path is
        // "on 401, re-POST /session". Two prepends, both landing immediately
        // before Authenticate, in declaration order: ResolveTenant first,
        // then WidgetCors inserted between it and Authenticate.
        $middleware->prependToPriorityList(
            before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: App\Http\Middleware\ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: App\Http\Middleware\WidgetCors::class,
        );

        // A GUEST hitting an authenticated page needs somewhere to go, and the
        // default target is route('login') — which does not exist (the login
        // SCREEN is the views step; only POST /login does). Without this line
        // the redirect itself threw and every gated page 500'd for guests.
        // The views step repoints this at the real login screen (FR-11 wants
        // the return URL preserved with a "session expired" notice there).
        $middleware->redirectGuestsTo('/');

        // Doc 02 SEC-06: cleartext is refused BEFORE anything else runs — no
        // session is read, no credential is parsed, no tenant is resolved on a
        // request whose secrets already crossed the wire in the open. Prepended
        // globally for that reason; see the middleware for why it has no
        // environment carve-out.
        $middleware->prepend(App\Http\Middleware\RequireSecureTransport::class);

        // The tenant hint is SIGNED, not secret (see TenantHint): its integrity
        // comes from an HMAC, and its authority from VerifyTenantHint comparing
        // it against the session's own user. Encrypting it on top would add no
        // security property the signature does not already give, while making
        // the value unreadable to the session handler that must consult it
        // BEFORE the session — and therefore before cookie decryption has
        // produced anything useful.
        $middleware->encryptCookies(except: [App\Support\Auth\Sessions\TenantHint::COOKIE]);

        // Doc 02 T-07: one security-header posture on every response, both
        // surfaces. Appended (not prepended) so it wraps the innermost work and
        // stamps headers even on responses produced by earlier middleware.
        // Q7's authority check: a session whose user does not belong to the
        // hinted tenant is discarded. In the group, not per route — a route that
        // forgot to declare it would be a route where the check does not happen.
        $middleware->appendToGroup('web', App\Http\Middleware\VerifyTenantHint::class);
        $middleware->appendToGroup('api', App\Http\Middleware\VerifyTenantHint::class);

        $middleware->appendToGroup('web', App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', App\Http\Middleware\SecurityHeaders::class);

        // FR-11's clocks on every authenticated WEB request. This line is what
        // makes the middleware real: S9 proved the POLICY with an injected
        // clock, but the middleware itself was attached nowhere — the same
        // configured-but-unwired failure S8 found in the session prefix. In
        // the group so an expired session is de-authenticated BEFORE any
        // route-level auth middleware runs, which is what turns expiry into
        // FR-11's redirect-to-login. The staff API group carries it in
        // routes/api.php, where its position against tenant resolution is
        // load-bearing and explained.
        $middleware->appendToGroup('web', App\Http\Middleware\EnforceSessionLifetime::class);

        // I-5 (Doc 01 → Doc 02 inherited task): the per-request user-locale hop
        // of C-08's chain. In the web GROUP so every authenticated page renders
        // in the user's language without each route opting in; a guest request
        // is a no-op. The staff API group appends it in routes/api.php, where
        // ordering against tenant middleware is explicit. After the lifetime
        // check: an expired session's refusal renders in the default locale.
        $middleware->appendToGroup('web', App\Http\Middleware\ApplyUserLocale::class);

        // FR-15's max-age clause. LAST in the group, deliberately: it needs an
        // authenticated principal (after session middleware), that principal's
        // tenant setting (after the hint/tenant plumbing), and its refusal
        // should render in the user's own locale (after the locale hop). A
        // guest, a disabled max-age (the default) and a fresh password are all
        // no-ops. The staff API group appends it in routes/api.php.
        $middleware->appendToGroup('web', App\Http\Middleware\EnforcePasswordExpiry::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
