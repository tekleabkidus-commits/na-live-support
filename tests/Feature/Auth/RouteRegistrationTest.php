<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

/**
 * Inherited task I-1 (Doc 01 → Doc 02): the authentication surface is registered
 * EXPLICITLY, with coverage, or not at all.
 *
 * Doc 01 turned Fortify's eighteen auto-registered routes off because they were live,
 * unspecified, and unaudited. Doc 02's answer is not to turn auto-registration back
 * on — it is to keep it off permanently and register each route by hand in
 * routes/auth.php as its flow is actually built, carrying its tenant, state, audit,
 * and throttle middleware. A route that exists before its handler is specified and
 * proven is exactly the defect Doc 01 flagged.
 *
 * The two "mirror" tests replicate the permission-mapping and audit-coverage CI
 * gates inside the pest suite. The gates already fail the build; the mirrors fail
 * the local red-green loop, which is minutes earlier and does not depend on the
 * developer remembering to run `make gates`.
 */
it('has removed the Doc 01 deferral and keeps Fortify auto-registration off permanently', function () {
    // The Doc 01 stopgap method is gone…
    expect(method_exists(AppServiceProvider::class, 'deferAuthenticationRoutesToDoc02'))->toBeFalse(
        'deferAuthenticationRoutesToDoc02() still exists — Doc 02 owns the auth surface now.',
    );

    // …and what replaced it is explicit registration, not silent re-enabling.
    expect(Fortify::$registersRoutes)->toBeFalse(
        'Fortify auto-registration is on: unspecified vendor routes are live again.',
    );
});

it('offers no self-registration route in v1', function () {
    // FR-17: users are created by admins and arrive invited. A public register
    // endpoint in a multi-tenant staff platform is an account-creation surface no
    // document specifies.
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toBe('register');
        expect((string) $route->getName())->not->toBe('register');
    }
});

it('maps or exempts every route in the permission registry (permission-mapping gate mirror)', function () {
    $permissions = require base_path('registries/permissions.php');
    $registered = array_flip(array_column($permissions, 'key'));
    $exempt = require base_path('ci/permission-exempt-routes.php');
    $vendorPrefixes = ['horizon', 'sanctum', 'up', 'storage', '_ignition', 'broadcasting'];

    // The gate only enforces once the registry is non-empty (bootstrap flip). Doc 02
    // registers the first keys, so from this session on the mirror always enforces.
    expect($registered)->not->toBeEmpty('Doc 02 registers the first permission keys');

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $name = (string) $route->getName();

        foreach ($vendorPrefixes as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                continue 2;
            }
        }
        if (isset($exempt[$uri]) || ($name !== '' && isset($exempt[$name]))) {
            continue;
        }

        $mapped = null;
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                $mapped = substr($mw, strlen('permission:'));
                break;
            }
        }

        expect($mapped)->not->toBeNull(sprintf(
            'Route "%s" (%s) carries no permission:<key> middleware and is not exempt.',
            $uri,
            $name !== '' ? $name : 'unnamed',
        ));
        expect(isset($registered[(string) $mapped]))->toBeTrue(sprintf(
            'Route "%s" declares unregistered permission key "%s".',
            $uri,
            $mapped,
        ));
    }
});

it('declares a registered audit action on every mutating route (audit-coverage gate mirror)', function () {
    $actions = require base_path('registries/audit-coverage.php');
    $registered = array_flip(array_column($actions, 'action_key'));
    $exempt = require base_path('ci/audit-exempt-routes.php');
    $vendorPrefixes = ['horizon', 'sanctum', 'up', 'storage', '_ignition', 'broadcasting'];
    $mutating = ['POST', 'PUT', 'PATCH', 'DELETE'];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $name = (string) $route->getName();

        if (array_intersect($route->methods(), $mutating) === []) {
            continue;
        }
        foreach ($vendorPrefixes as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                continue 2;
            }
        }
        if (isset($exempt[$uri]) || ($name !== '' && isset($exempt[$name]))) {
            continue;
        }

        $action = null;
        foreach ($route->gatherMiddleware() as $mw) {
            if (! is_string($mw) || ! str_contains($mw, ':')) {
                continue;
            }
            [$head, $tail] = explode(':', $mw, 2);
            if ($head === 'audit' || str_ends_with($head, 'DeclareAuditAction')) {
                $action = $tail;
                break;
            }
        }

        expect($action)->not->toBeNull(sprintf(
            'Mutating route "%s" (%s) declares no audit action and is not exempt.',
            $uri,
            $name !== '' ? $name : 'unnamed',
        ));
        expect(isset($registered[(string) $action]))->toBeTrue(sprintf(
            'Route "%s" declares unregistered audit action "%s".',
            $uri,
            $action,
        ));
    }
});
