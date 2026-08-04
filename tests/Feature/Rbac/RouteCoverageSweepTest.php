<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * AC-01 + SEC-01 + the T-02 deny shape, over every route that EXISTS.
 *
 * The route→permission TABLE is the permission-mapping gate (blocking since
 * Doc 02's first registry entry: an unmapped route fails the build — that is
 * NFR-06/US-06, falsified in the gate's own suite). What THIS suite adds is
 * the enforcement half: hitting every permission-declared staff route with an
 * under-privileged principal yields 403 `{code:"forbidden", permission:key}`
 * naming the declared key (SEC-02's staff shape), logged and counted (FR-11)
 * — and a positive control proving a holder passes the same middleware. The
 * table EXTENDS AUTOMATICALLY as later docs land, because the gate forces
 * every new route to declare and this suite discovers declarations at
 * runtime.
 */
function permissionDeclaredStaffRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $routes[] = [
                    'method' => $route->methods()[0],
                    'uri' => $route->uri(),
                    'permission' => substr($middleware, strlen('permission:')),
                ];
            }
        }
    }

    return $routes;
}

it('refuses EVERY permission-declared staff route to an under-privileged principal, naming the key (AC-01, SEC-01, SEC-02, T-02)', function () {
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => grantPermissions(User::factory()->create(), [], 'No Permissions Role'));
    activateTenant($tenant);
    $logs = &capturedLogsRef();

    $routes = permissionDeclaredStaffRoutes();
    // The sweep must actually sweep: Doc 02's surface alone declares 10+.
    expect(count($routes))->toBeGreaterThanOrEqual(10);

    foreach ($routes as $route) {
        $uri = preg_replace('/\{[^}]+\}/', (string) Str::uuid7(), $route['uri']);

        $response = test()->actingAs($user, 'staff')->json($route['method'], 'https://localhost/'.$uri);

        expect($response->status())->toBe(403, "{$route['method']} {$route['uri']} did not deny")
            ->and($response->json('code'))->toBe('forbidden', "{$route['method']} {$route['uri']} wrong code")
            ->and($response->json('permission'))->toBe($route['permission'], "{$route['method']} {$route['uri']} did not name its key");
    }

    // FR-11: the failures were logged with the named metric.
    $denyLogs = collect($logs)->filter(
        fn (array $log) => ($log['context']['security_metric'] ?? null) === 'rbac.denied_checks',
    );
    expect($denyLogs->count())->toBeGreaterThanOrEqual(count($routes))
        ->and($denyLogs->first()['context'])->toHaveKeys(['permission', 'actor_id', 'trace_id']);
});

it('POSITIVE CONTROL: a holder passes the same middleware on the same surface', function () {
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => grantPermissions(User::factory()->create(), ['settings.view'], 'Settings Viewer'));
    activateTenant($tenant);

    test()->actingAs($user, 'staff')->getJson('https://localhost/api/staff/settings')->assertOk();
});

it('masks object existence on out-of-scope reads — 404, never 403, for a foreign id (SEC-02, C-10)', function () {
    $tenant = createTenant();
    $admin = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create()));
    activateTenant($tenant);

    $otherTenant = createTenant();
    $foreignUser = asTenant($otherTenant, fn (): User => User::factory()->create());
    activateTenant($otherTenant);

    bindTenant($tenant);

    // The admin HOLDS admin.users.manage — the verb is allowed — but the
    // OBJECT belongs to another tenant: the answer is indistinguishable from
    // "no such user", never a 403 that confirms existence.
    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/users/'.$foreignUser->identifier().'/unlock')
        ->assertNotFound();
});
