<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * T-02: the middleware order is a specified artifact, not an accident.
 *
 * Doc 02 fixes the order: tenant-resolve → guard → tenant-state → 2FA-enforcement →
 * RBAC (Doc 03). This suite reads the REAL route table and asserts relative order on
 * the routes that exist, so a refactor that quietly swaps two middleware fails a
 * test rather than shipping a subtle trust inversion (e.g. a permission check that
 * runs before the tenant's suspension is enforced).
 *
 * The suite grows with the route table: the 2FA-enforcement position is asserted from
 * step 7 when the middleware exists, and Doc 03's RBAC check when Doc 03 lands.
 */
function middlewarePositions(string $routeName): array
{
    $route = Route::getRoutes()->getByName($routeName);
    expect($route)->not->toBeNull("route {$routeName} is not registered");

    $stack = $route->gatherMiddleware();

    $positions = [];
    foreach ($stack as $i => $mw) {
        if (! is_string($mw)) {
            continue;
        }
        $positions[$mw] = $i;
    }

    return $positions;
}

function positionOf(array $positions, string $prefix): ?int
{
    foreach ($positions as $mw => $i) {
        if (str_starts_with($mw, $prefix)) {
            return $i;
        }
    }

    return null;
}

it('orders tenant-resolve before tenant-state before permission on the staff settings surface', function () {
    $positions = middlewarePositions('foundation.settings.update');

    $tenant = positionOf($positions, 'tenant:');
    $state = positionOf($positions, 'tenant.state:');
    $permission = positionOf($positions, 'permission:');

    expect($tenant)->not->toBeNull('tenant resolution missing')
        ->and($state)->not->toBeNull('tenant state enforcement missing')
        ->and($permission)->not->toBeNull('permission declaration missing')
        ->and($tenant)->toBeLessThan($state)
        ->and($state)->toBeLessThan($permission);
});

it('never enforces tenant state on a route that has not resolved a tenant', function () {
    foreach (Route::getRoutes() as $route) {
        $stack = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

        $state = null;
        $tenant = null;
        foreach ($stack as $i => $mw) {
            if (str_starts_with($mw, 'tenant.state:')) {
                $state = $i;
            }
            // Two resolver spellings: the six-source binder (`tenant:<source>`)
            // and the bearer surface's own binder (Doc 02's token step).
            if (str_starts_with($mw, 'tenant:') || $mw === 'tenant.bearer') {
                $tenant = $i;
            }
        }

        if ($state === null) {
            continue;
        }

        expect($tenant)->not->toBeNull(sprintf(
            'Route %s enforces tenant.state without resolving a tenant first',
            $route->uri(),
        ))->and($tenant)->toBeLessThan($state, sprintf(
            'Route %s resolves the tenant AFTER enforcing its state',
            $route->uri(),
        ));
    }
});

it('orders the EXECUTED bearer stack binder-first — the sorter, not the declaration (T-02)', function () {
    // The route DECLARES tenant.bearer → auth:api → tenant.state, but Laravel
    // re-sorts any stack containing priority-listed middleware, and
    // Authenticate is priority-listed: without the bootstrap priority entry it
    // HOPPED over the (unlisted) binder and the Sanctum guard ran with no
    // tenant bound — every bearer request 500'd. The route table looked
    // perfect the whole time, which is why this asserts the SORTED stack the
    // pipeline will actually execute, not the declared one.
    $route = Route::getRoutes()->getByName('auth.token.context');
    expect($route)->not->toBeNull('route auth.token.context is not registered');

    $sorted = app('router')->gatherRouteMiddleware($route);

    $binder = array_search(App\Http\Middleware\ResolveBearerTenant::class, $sorted, true);
    $guard = null;
    $state = null;

    foreach ($sorted as $i => $mw) {
        if (is_string($mw) && str_starts_with($mw, Illuminate\Auth\Middleware\Authenticate::class)) {
            $guard = $i;
        }
        if (is_string($mw) && str_starts_with($mw, App\Http\Middleware\EnforceTenantState::class)) {
            $state = $i;
        }
    }

    expect($binder)->not->toBeFalse('bearer binder missing from the executed stack')
        ->and($guard)->not->toBeNull('auth:api guard missing from the executed stack')
        ->and($state)->not->toBeNull('tenant.state missing from the executed stack')
        ->and($binder)->toBeLessThan($guard, 'the guard would run with no tenant bound')
        ->and($guard)->toBeLessThan($state);
});

it('keeps the bearer surface stateless — no session, no cookies, no CSRF in the executed stack (T-06)', function () {
    // "Bearer API CSRF-exempt" is implemented as ABSENCE, not an exemption
    // list: with no StartSession there is no session to ride, so CSRF has
    // nothing to protect. This asserts the absence stays absent.
    $route = Route::getRoutes()->getByName('auth.token.context');
    $sorted = app('router')->gatherRouteMiddleware($route);

    foreach ($sorted as $mw) {
        if (! is_string($mw)) {
            continue;
        }

        expect($mw)
            ->not->toContain('StartSession')
            ->not->toContain('ValidateCsrfToken')
            ->not->toContain('EncryptCookies');
    }
});

it('orders the EXECUTED widget stack binder → CORS → guard → state (T-02, step 10)', function () {
    // The widget guard is DELIBERATELY not Laravel's Authenticate: that class
    // is priority-listed, the sorter hoists it over unlisted predecessors
    // (the step-9 bug), and the first fix — listing ResolveTenant globally —
    // re-sorted the STAFF stack and stripped T-07 headers off its error
    // responses. AuthenticateWidgetVisitor is invisible to the sorter, so
    // this asserts both the executed order AND Authenticate's absence: if
    // someone "simplifies" back to auth:widget, the absence clause goes red
    // before the subtle ordering bug does.
    $route = Route::getRoutes()->getByName('auth.widget.context');
    expect($route)->not->toBeNull('route auth.widget.context is not registered');

    $sorted = app('router')->gatherRouteMiddleware($route);

    $binder = null;
    $cors = null;
    $guard = null;
    $state = null;

    foreach ($sorted as $i => $mw) {
        if (! is_string($mw)) {
            continue;
        }
        if (str_starts_with($mw, App\Http\Middleware\ResolveTenant::class)) {
            $binder = $i;
        }
        if (str_starts_with($mw, App\Http\Middleware\WidgetCors::class)) {
            $cors = $i;
        }
        if (str_starts_with($mw, App\Http\Middleware\AuthenticateWidgetVisitor::class)) {
            $guard = $i;
        }
        if (str_starts_with($mw, Illuminate\Auth\Middleware\Authenticate::class)) {
            test()->fail('Laravel\'s Authenticate is on the widget stack — the sorter will hoist it over the binder; use widget.auth (AuthenticateWidgetVisitor) instead.');
        }
        if (str_starts_with($mw, App\Http\Middleware\EnforceTenantState::class)) {
            $state = $i;
        }
    }

    expect($binder)->not->toBeNull('widget binder missing from the executed stack')
        ->and($cors)->not->toBeNull('WidgetCors missing from the executed stack')
        ->and($guard)->not->toBeNull('widget guard middleware missing from the executed stack')
        ->and($state)->not->toBeNull('tenant.state missing from the executed stack')
        ->and($binder)->toBeLessThan($cors, 'CORS would stamp against a cleared RLS scope')
        ->and($cors)->toBeLessThan($guard, 'the guard 401 would leave CORS-unreadable')
        ->and($guard)->toBeLessThan($state);
});

it('keeps the widget surface sessionless — no session, no cookie encryption, no CSRF in the executed stack (BR-06, T-06)', function () {
    // BR-06's "separate cookie scope" as an absence: the FR-22 visitor cookie
    // is set raw and read raw, so no cookie middleware belongs here — and a
    // staff session cookie arriving on this surface is inert bytes, because
    // nothing on the stack even decrypts it.
    $route = Route::getRoutes()->getByName('auth.widget.session');
    $sorted = app('router')->gatherRouteMiddleware($route);

    foreach ($sorted as $mw) {
        if (! is_string($mw)) {
            continue;
        }

        expect($mw)
            ->not->toContain('StartSession')
            ->not->toContain('ValidateCsrfToken')
            ->not->toContain('EncryptCookies');
    }
});

it('registers the permission alias to the RequirePermission middleware', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('permission')
        ->and($aliases['permission'])->toBe(App\Http\Middleware\RequirePermission::class);
});
