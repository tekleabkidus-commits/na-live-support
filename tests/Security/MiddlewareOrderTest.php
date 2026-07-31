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
            if (str_starts_with($mw, 'tenant:')) {
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

it('registers the permission alias to the RequirePermission middleware', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('permission')
        ->and($aliases['permission'])->toBe(App\Http\Middleware\RequirePermission::class);
});
