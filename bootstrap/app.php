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

        // Doc 02 SEC-06: cleartext is refused BEFORE anything else runs — no
        // session is read, no credential is parsed, no tenant is resolved on a
        // request whose secrets already crossed the wire in the open. Prepended
        // globally for that reason; see the middleware for why it has no
        // environment carve-out.
        $middleware->prepend(App\Http\Middleware\RequireSecureTransport::class);

        // Doc 02 T-07: one security-header posture on every response, both
        // surfaces. Appended (not prepended) so it wraps the innermost work and
        // stamps headers even on responses produced by earlier middleware.
        $middleware->appendToGroup('web', App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
