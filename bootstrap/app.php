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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
