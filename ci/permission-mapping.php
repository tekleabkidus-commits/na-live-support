<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| permission-mapping gate (Doc 03; CLAUDE.md §2 permissions registry rule)
|------------------------------------------------------------------------------
|
| Every registered route must map to a registered permission key. The mapping
| contract (frozen here, implemented by Doc 03 in Session 3): a route is
| mapped when it carries `permission:<key>` middleware, and <key> exists in
| registries/permissions.php. Routes that are legitimately public are listed
| in ci/permission-exempt-routes.php with a reason.
|
| BOOTSTRAP FLIP (explicit, not a TODO):
|   - registries/permissions.php EMPTY  → report unmapped routes as WARN,
|     exit 0. Rationale: the RBAC module does not exist yet; failing would
|     force fake permission keys, which is worse than an honest warning.
|   - registry NON-EMPTY (first key added) → same check exits 1 on any
|     unmapped, non-exempt route. No grace period.
|
| Exit codes: 0 = pass (or bootstrap warn), 1 = violations.
|
*/

$root = dirname(__DIR__);

/** @var list<mixed> $permissions */
$permissions = require $root.'/registries/permissions.php';

/** @var array<string, string> $exempt route-name-or-uri => reason */
$exempt = require __DIR__.'/permission-exempt-routes.php';

$registeredKeys = [];
foreach ($permissions as $entry) {
    if (is_array($entry) && isset($entry['key']) && is_string($entry['key'])) {
        $registeredKeys[$entry['key']] = true;
    }
}

// Pull the real route table from the application itself.
exec(sprintf('cd %s && php artisan route:list --json 2>/dev/null', escapeshellarg($root)), $outputLines, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "permission-mapping: FAIL — `php artisan route:list --json` exited $exitCode\n");
    exit(1);
}
/** @var mixed $routes */
$routes = json_decode(implode("\n", $outputLines), true);
if (! is_array($routes)) {
    fwrite(STDERR, "permission-mapping: FAIL — could not parse route:list output\n");
    exit(1);
}

// Framework/vendor surfaces are not tenant feature routes.
$vendorPrefixes = ['horizon', 'sanctum', 'up', 'storage', '_ignition', 'broadcasting'];

$unmapped = [];
foreach ($routes as $route) {
    if (! is_array($route)) {
        continue;
    }
    $uri = is_string($route['uri'] ?? null) ? $route['uri'] : '';
    $name = is_string($route['name'] ?? null) ? $route['name'] : '';
    $middleware = $route['middleware'] ?? [];
    $middlewareList = is_array($middleware) ? $middleware : (is_string($middleware) ? explode(',', $middleware) : []);

    foreach ($vendorPrefixes as $prefix) {
        if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
            continue 2;
        }
    }
    if (isset($exempt[$uri]) || ($name !== '' && isset($exempt[$name]))) {
        continue;
    }

    $mappedKey = null;
    foreach ($middlewareList as $mw) {
        if (is_string($mw) && str_starts_with($mw, 'permission:')) {
            $mappedKey = substr($mw, strlen('permission:'));
            break;
        }
    }

    if ($mappedKey === null) {
        $unmapped[] = sprintf('%s (%s)', $uri, $name !== '' ? $name : 'unnamed');
    } elseif (! isset($registeredKeys[$mappedKey])) {
        $unmapped[] = sprintf('%s maps to UNREGISTERED key "%s"', $uri, $mappedKey);
    }
}

$bootstrap = $registeredKeys === [];

if ($unmapped !== []) {
    $stream = $bootstrap ? STDOUT : STDERR;
    foreach ($unmapped as $u) {
        fwrite($stream, sprintf("permission-mapping: %s route without permission mapping: %s\n", $bootstrap ? 'WARN' : 'FAIL', $u));
    }
    if ($bootstrap) {
        printf(
            'permission-mapping: WARN — %d unmapped route(s), tolerated ONLY while registries/permissions.php is empty. '
            ."The first registered key flips this gate to blocking.\n",
            count($unmapped),
        );
        exit(0);
    }
    fwrite(STDERR, sprintf(
        'permission-mapping: FAIL (%d unmapped route(s)). Registry has %d key(s), so bootstrap mode is over: '
        ."every route needs `permission:<key>` middleware or an entry in ci/permission-exempt-routes.php.\n",
        count($unmapped),
        count($registeredKeys),
    ));
    exit(1);
}

printf("permission-mapping: PASS (%s)\n", $bootstrap ? 'no unmapped routes; registry empty — bootstrap mode' : 'all routes mapped');
exit(0);
