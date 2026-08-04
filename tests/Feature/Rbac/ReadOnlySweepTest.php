<?php

declare(strict_types=1);

use App\Domain\Rbac\RoleSeeder;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * AC-06 / BR-05: the SEEDED Read Only template passes a full mutation-route
 * sweep with zero accepted writes.
 *
 * Sweep boundary, stated plainly: every permission-DECLARED route with a
 * mutating verb (POST/PUT/PATCH/DELETE) — the set that grows automatically
 * as later docs land, because the permission-mapping gate forces every new
 * route to declare. Self-service auth actions (logout, own tokens, own 2FA)
 * are permission-exempt BY CONSTRUCTION (ci/permission-exempt-routes.php,
 * self-scoped like the sessions surface) and are not domain writes — a Read
 * Only user must still be able to log out.
 */
it('lets the seeded Read Only template accept ZERO writes across every permission-declared mutation route (AC-06, BR-05)', function () {
    $tenant = createTenant();
    $reader = asTenant($tenant, function (): User {
        app(RoleSeeder::class)->seed();
        $reader = User::factory()->create();
        $reader->forceFill(['role_id' => Role::query()->where('name', 'Read Only')->value('id')])->save();

        return $reader;
    });
    activateTenant($tenant);

    $mutations = [];
    foreach (Route::getRoutes() as $route) {
        $method = $route->methods()[0];
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            continue;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $mutations[] = ['method' => $method, 'uri' => $route->uri()];
            }
        }
    }
    expect(count($mutations))->toBeGreaterThanOrEqual(10);

    foreach ($mutations as $route) {
        $uri = preg_replace('/\{[^}]+\}/', (string) Str::uuid7(), $route['uri']);

        $status = test()->actingAs($reader, 'staff')
            ->json($route['method'], 'https://localhost/'.$uri)
            ->status();

        // AC-06's contract is ZERO ACCEPTED WRITES. Almost every route
        // refuses 403 at the middleware; the four-eyes decide routes carry a
        // view-tier key (any approver class may REACH them) and refuse
        // deeper — at the object lookup (404 here) or the R-9 rights check
        // (422, asserted explicitly in SafetyRailsTest). Accepted = 2xx/3xx,
        // and none is tolerated.
        expect($status)->toBeGreaterThanOrEqual(400, "SEV-1: {$route['method']} {$route['uri']} ACCEPTED a Read Only write ({$status})");
    }

    // Positive control: the same principal READS fine.
    test()->actingAs($reader, 'staff')->getJson('https://localhost/api/staff/settings')->assertOk();
});
