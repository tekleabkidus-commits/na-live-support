<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * SEC-06: "Auth refuses to operate over plain HTTP in any environment."
 *
 * The phrase that makes this hard is IN ANY ENVIRONMENT. Laravel's stock posture
 * is the opposite — secure cookies and HTTPS URLs are usually switched on for
 * production only — so the easy implementation ("enforce when APP_ENV=production")
 * would read as done and be exactly what the spec forbids. These tests therefore
 * run in the TESTING environment and assert refusal there: if someone reintroduces
 * an environment carve-out, this suite goes red rather than passing quietly in the
 * one environment nobody browses.
 *
 * The transport is enforced twice, independently, and both layers matter:
 *   - Caddy answers :80 with a 308 redirect and serves nothing (docker/app/Caddyfile).
 *   - This middleware refuses a cleartext request that reaches PHP anyway — which
 *     is what happens when a reverse proxy is misconfigured, the classic way an
 *     "HTTPS site" quietly serves cleartext to anyone inside the perimeter.
 */
function secureActor(): User
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create());
    activateTenant($tenant);
    app(TenantContext::class)->clear();

    return $user;
}

it('refuses a cleartext request in the testing environment', function () {
    // Laravel's test client defaults to http://localhost — i.e. exactly the
    // cleartext case, without needing to simulate anything.
    $response = $this->get('http://localhost/');

    $response->assertStatus(426);
    expect($response->headers->get('Upgrade'))->toBe('TLS/1.2, HTTP/1.1');
});

it('refuses a cleartext API request with the problem-details shape', function () {
    $response = $this->getJson('http://localhost/api/staff/settings');

    $response->assertStatus(426)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'insecure_transport')
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'detail', 'trace_id']);
});

it('serves the same request over TLS', function () {
    $this->get('https://localhost/')->assertOk();
});

it('refuses cleartext even when the app believes it is production', function () {
    // The carve-out that would silently satisfy every other test in this file:
    // "enforce only outside local/testing". Asserting refusal under BOTH
    // environments is what closes it.
    app()->detectEnvironment(fn () => 'production');

    $this->get('http://localhost/')->assertStatus(426);
});

it('honours a trusted proxy\'s X-Forwarded-Proto rather than double-refusing', function () {
    // Behind a TLS-terminating load balancer (Doc 18 FR-12's production shape),
    // PHP sees a cleartext connection carrying X-Forwarded-Proto: https. Refusing
    // that would make the middleware unusable in exactly the deployment it is
    // meant to protect — so the check reads the resolved scheme, and the trusted
    // proxy configuration is what decides whether the header is believed.
    config(['app.url' => 'https://localhost']);

    $response = $this->withServerVariables([
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'REMOTE_ADDR' => '10.0.0.1',
    ])->get('http://localhost/');

    $response->assertOk();
})->skip('Trusted-proxy configuration is Doc 18 FR-12 infrastructure work; the header is not trusted yet, and trusting it before the proxy exists would be a spoofable bypass.');

it('marks the session cookie secure and http-only in every environment', function () {
    // FR-10's cookie flags. Same reasoning as above: asserted in testing, where
    // the stock framework default would be to relax them.
    expect(config('session.secure'))->toBeTrue()
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});
