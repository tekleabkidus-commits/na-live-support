<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| audit-coverage gate (Doc 17; CLAUDE.md §2 audit-coverage registry rule)
|------------------------------------------------------------------------------
|
| Every state-mutating action must have an audit-coverage entry. The
| mechanical contract (frozen here, implemented from Doc 01 onward): a
| state-mutating route (POST/PUT/PATCH/DELETE) declares its audit action via
| `audit:<action_key>` middleware, and <action_key> exists in
| registries/audit-coverage.php. Mutating vendor surfaces and legitimately
| unaudited mutations are listed in ci/audit-exempt-routes.php with a reason.
|
| BOOTSTRAP FLIP (explicit, not a TODO):
|   - registries/audit-coverage.php EMPTY → unaudited mutating routes are
|     reported as WARN, exit 0 (the audit engine is Doc 17; early sessions
|     register their actions as they land).
|   - registry NON-EMPTY → same check exits 1. No grace period.
|
| Exit codes: 0 = pass (or bootstrap warn), 1 = violations.
|
*/

$root = dirname(__DIR__);

/** @var list<array<string, mixed>> $coverage */
$coverage = require $root.'/registries/audit-coverage.php';

/** @var array<string, string> $exempt route-uri-or-name => reason */
$exempt = require __DIR__.'/audit-exempt-routes.php';

$registeredActions = [];
foreach ($coverage as $entry) {
    if (is_array($entry) && isset($entry['action_key']) && is_string($entry['action_key'])) {
        $registeredActions[$entry['action_key']] = true;
    }
}

exec(sprintf('cd %s && php artisan route:list --json 2>/dev/null', escapeshellarg($root)), $outputLines, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "audit-coverage: FAIL — `php artisan route:list --json` exited $exitCode\n");
    exit(1);
}
/** @var mixed $routes */
$routes = json_decode(implode("\n", $outputLines), true);
if (! is_array($routes)) {
    fwrite(STDERR, "audit-coverage: FAIL — could not parse route:list output\n");
    exit(1);
}

$vendorPrefixes = ['horizon', 'sanctum', 'up', 'storage', '_ignition', 'broadcasting'];
$mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

$uncovered = [];
foreach ($routes as $route) {
    if (! is_array($route)) {
        continue;
    }
    $uri = is_string($route['uri'] ?? null) ? $route['uri'] : '';
    $name = is_string($route['name'] ?? null) ? $route['name'] : '';
    $method = is_string($route['method'] ?? null) ? $route['method'] : '';
    $middleware = $route['middleware'] ?? [];
    $middlewareList = is_array($middleware) ? $middleware : (is_string($middleware) ? explode(',', $middleware) : []);

    $isMutating = false;
    foreach ($mutatingMethods as $m) {
        if (str_contains($method, $m)) {
            $isMutating = true;
            break;
        }
    }
    if (! $isMutating) {
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
    foreach ($middlewareList as $mw) {
        if (is_string($mw) && str_starts_with($mw, 'audit:')) {
            $action = substr($mw, strlen('audit:'));
            break;
        }
    }

    if ($action === null) {
        $uncovered[] = sprintf('%s %s (%s)', $method, $uri, $name !== '' ? $name : 'unnamed');
    } elseif (! isset($registeredActions[$action])) {
        $uncovered[] = sprintf('%s %s declares UNREGISTERED audit action "%s"', $method, $uri, $action);
    }
}

$bootstrap = $registeredActions === [];

if ($uncovered !== []) {
    $stream = $bootstrap ? STDOUT : STDERR;
    foreach ($uncovered as $u) {
        fwrite($stream, sprintf("audit-coverage: %s mutating route without audit coverage: %s\n", $bootstrap ? 'WARN' : 'FAIL', $u));
    }
    if ($bootstrap) {
        printf(
            "audit-coverage: WARN — %d uncovered mutating route(s), tolerated ONLY while registries/audit-coverage.php is empty. "
            ."The first registered action flips this gate to blocking.\n",
            count($uncovered),
        );
        exit(0);
    }
    fwrite(STDERR, sprintf("audit-coverage: FAIL (%d uncovered mutating route(s)).\n", count($uncovered)));
    exit(1);
}

printf("audit-coverage: PASS (%s)\n", $bootstrap ? 'no mutating routes; registry empty — bootstrap mode' : 'all mutating routes covered');
exit(0);
