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
            // Doc 05: copies the webhook URL's {bot_key} segment into the
            // header ResolveTenant reads (Telegram cannot send X-Tenant-Key).
            'telegram.webhook-key' => App\Http\Middleware\TelegramWebhookKeyFromPath::class,
            // Declares which audit action a mutating route emits, and verifies at runtime
            // that it actually did. The `audit-coverage` gate reads these declarations.
            'audit' => App\Http\Middleware\DeclareAuditAction::class,
            // Doc 02 T-02/T-05: declares which permission a route requires. The
            // permission-mapping gate reads these; enforcement layers attach here
            // (token-scope intersection in Doc 02's token step, staff verdicts in
            // Doc 03). See the middleware's docblock before assuming it enforces.
            'permission' => App\Http\Middleware\RequirePermission::class,
            // Doc 02 FR-23/FR-24/FR-25: the webhook second credential and the
            // per-key limit. Both are plain classes the middleware SORTER
            // cannot see (neither implements a priority-listed contract) —
            // declared order on the webhook stacks is executed order, which
            // MiddlewareOrderTest asserts. See each class's docblock.
            'webhook.auth' => App\Http\Middleware\VerifyWebhookSecret::class,
            'webhook.throttle' => App\Http\Middleware\ThrottleWebhookKey::class,
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

        // The widget stack (Doc 02 step 10) deliberately does NOT add its
        // middleware to this list. The first attempt did — ResolveTenant and
        // WidgetCors prepended before Authenticate, mirroring the bearer fix —
        // and it broke a DIFFERENT surface: ResolveTenant sits on the staff
        // routes too, so listing it re-sorted the staff stack, hoisted the
        // binder above the group-appended SecurityHeaders, and every staff
        // 404 rendered inside the binder left WITHOUT its T-07 headers
        // (SecurityHeadersTest went red). The priority list is GLOBAL; a rule
        // added for one surface moves every stack that middleware appears on.
        // The widget surface instead authenticates via widget.auth
        // (AuthenticateWidgetVisitor), which does not implement the
        // priority-listed AuthenticatesRequests contract — nothing hoists,
        // declared order IS executed order, asserted by MiddlewareOrderTest.

        // A GUEST hitting an authenticated page goes to the login card
        // (step 13). The framework's guest redirect stores the intended URL in
        // the session, which is FR-11's "preserving the return URL" — the card
        // reads it back through SafeReturnUrl (never verbatim). The expired
        // NOTICE is EnforceSessionLifetime's flag; see that middleware.
        $middleware->redirectGuestsTo('/login');

        // The mirror case: an authenticated user visiting the login card is
        // redirected home rather than re-authenticated. Explicit because the
        // framework default targets a route this application does not have.
        $middleware->redirectUsersTo('/');

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
        $middleware->encryptCookies(except: [
            App\Support\Auth\Sessions\TenantHint::COOKIE,
            // §14's language switcher writes this from the login card BEFORE any
            // principal exists, so the client must be able to set it — an
            // encrypted cookie can only be minted server-side. It carries no
            // authority: ApplyUserLocale accepts exactly `en` or `am` (owner
            // ruling, step 13) and an authenticated user's own locale always
            // wins over it.
            App\Http\Middleware\ApplyUserLocale::LOCALE_COOKIE,
        ]);

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
        // configured-but-unwired failure S8 found in the session prefix.
        // NOTE (step 13): the middleware does NOT run before route-level
        // `auth:staff` — the sorter hoists Authenticate above it (S13's
        // lesson, replayed here) — so it issues FR-11's redirect ITSELF; see
        // its docblock. The staff API group carries it in routes/api.php,
        // where its position against tenant resolution is load-bearing and
        // explained.
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
        // Doc 02 FR-09 (closed at S19 with the role resolver): required-role
        // 2FA enforcement, grace countdown; runs after auth + password expiry.
        $middleware->appendToGroup('web', App\Http\Middleware\EnforceTwoFactorForRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
