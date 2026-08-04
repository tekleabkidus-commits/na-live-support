<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * T-07: security headers everywhere (Doc 02 §10).
 *
 * "Everywhere" is the requirement, so these tests hit three deliberately different
 * surfaces — a web page, an authenticated staff API route, and an error response —
 * and assert the same posture on each. A security header that only appears on happy
 * paths is a header an attacker never meets.
 *
 * WHY THE NONCE ASSERTIONS LOOK PARANOID. A CSP with 'unsafe-inline' is decoration:
 * any injected <script> runs. Nonce-based CSP is only real if (a) no unsafe-inline
 * appears anywhere in the policy, and (b) the nonce actually rotates per request —
 * a constant nonce is a password printed in every response. Both are asserted, not
 * assumed.
 */
function headersActor(): User
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create()));
    activateTenant($tenant);
    app(TenantContext::class)->clear();

    return $user;
}

/**
 * @return array<string, string|null>
 */
function headerBag(Illuminate\Testing\TestResponse $response): array
{
    return [
        'csp' => $response->headers->get('Content-Security-Policy'),
        'hsts' => $response->headers->get('Strict-Transport-Security'),
        'xcto' => $response->headers->get('X-Content-Type-Options'),
        'referrer' => $response->headers->get('Referrer-Policy'),
        'xfo' => $response->headers->get('X-Frame-Options'),
    ];
}

function assertStaffPosture(Illuminate\Testing\TestResponse $response): void
{
    $h = headerBag($response);

    expect($h['csp'])->not->toBeNull()
        ->and($h['csp'])->toContain("default-src 'self'")
        ->and($h['csp'])->toContain("'nonce-")
        ->and($h['csp'])->not->toContain('unsafe-inline')
        ->and($h['csp'])->not->toContain('unsafe-eval')
        // Staff surfaces may never be framed (T-07: frame-ancestors none for staff).
        ->and($h['csp'])->toContain("frame-ancestors 'none'")
        ->and($h['csp'])->toContain("object-src 'none'")
        ->and($h['csp'])->toContain("base-uri 'self'")
        // HSTS: 2 years, preload (T-07). 63072000 = 730 days exactly.
        ->and($h['hsts'])->toBe('max-age=63072000; includeSubDomains; preload')
        ->and($h['xcto'])->toBe('nosniff')
        ->and($h['referrer'])->toBe('strict-origin-when-cross-origin')
        // Belt for pre-CSP user agents; CSP frame-ancestors is the braces.
        ->and($h['xfo'])->toBe('DENY');
}

it('carries the full T-07 header set on the root web page', function () {
    assertStaffPosture($this->get('/'));
});

it('carries the full T-07 header set on an authenticated staff API response', function () {
    $user = headersActor();

    $response = $this->actingAs($user)->getJson('/api/staff/settings');
    $response->assertOk();

    assertStaffPosture($response);
});

it('carries the full T-07 header set on an error response', function () {
    // An unresolvable staff request 404s by design (Doc 01 SEC-06). The security
    // posture must not depend on the request having succeeded.
    assertStaffPosture($this->getJson('/api/staff/context'));
});

it('rotates the CSP nonce on every request', function () {
    $first = $this->get('/')->headers->get('Content-Security-Policy');
    $second = $this->get('/')->headers->get('Content-Security-Policy');

    $extract = function (?string $csp): string {
        expect($csp)->not->toBeNull();
        preg_match("/'nonce-([^']+)'/", (string) $csp, $m);
        expect($m)->toHaveCount(2, "CSP carries no nonce: {$csp}");

        return $m[1];
    };

    $nonceA = $extract($first);
    $nonceB = $extract($second);

    expect($nonceA)->not->toBe($nonceB)
        // 16 random bytes base64-encoded — length pins entropy was not quietly reduced.
        ->and(strlen(base64_decode($nonceA, true) ?: ''))->toBeGreaterThanOrEqual(16);
});
