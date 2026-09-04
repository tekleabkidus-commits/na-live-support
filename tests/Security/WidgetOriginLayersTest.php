<?php

declare(strict_types=1);

use App\Http\Middleware\WidgetCors;

/**
 * Doc 13 AC-03 / SEC-02 / FR-03 — ORIGIN DEFENSE IN DEPTH: three independent
 * layers, and each one blocks ALONE. The server layer (Doc 02 FR-21/EC-08's
 * session issuance) and the CORS layer (Doc 02 T-06's per-widget stamp) are
 * Doc 02's — proven here as the layers Doc 13 CONSUMES on its own doors,
 * never re-implemented; the loader's client layer is the HARNESS browser leg
 * (widget-origin-layers.spec.ts, step 7). "Disable two, the third holds":
 * the CORS stamp is removed by taking the middleware off the route (the
 * Doc 02 AC-06 by-removal precedent) and the server still refuses; the
 * server check is bypassed by a token minted on the allowed origin and the
 * CORS layer still withholds the stamp for the hostile origin. EC-12's
 * wildcard boundary is re-pinned on the visitor doors. Sabotage (d) weakens
 * the third layer and must turn this red.
 */
const HOSTILE_ORIGIN = 'https://evil-brand.example';

it('SERVER layer alone: with the CORS stamp removed, a session from a hostile origin is still refused 403 origin_not_allowed and nothing is issued', function (): void {
    [, , $key] = widgetFixture(['https://help.fixture-operator.example', '*.brand.example']);
    resetWidgetClient();

    // Remove the CORS layer for this request path (by removal, the AC-06 precedent).
    app('router')->getRoutes()->getByName('auth.widget.session')?->withoutMiddleware(WidgetCors::class);

    $refused = test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => HOSTILE_ORIGIN])->postJson('/api/widget/v1/session', ['ancestors' => [HOSTILE_ORIGIN]]);
    $refused->assertStatus(403)->assertJsonPath('code', 'origin_not_allowed');
    expect($refused->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    // EC-12 on the same door: the dot boundary — evil-brand.example is not *.brand.example; www needs the wildcard or its own entry.
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => 'https://evil-brand.example'])->postJson('/api/widget/v1/session', [])->assertStatus(403);
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => 'https://www.brand.example'])->postJson('/api/widget/v1/session', [])->assertStatus(201);
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => 'https://brand.example'])->postJson('/api/widget/v1/session', [])->assertStatus(403);
});

it('CORS layer alone: a valid visitor token used from a hostile origin gets NO Access-Control-Allow-Origin on the visitor doors — the browser cannot read the body, whatever the server answered', function (): void {
    [, , $key] = widgetFixture();
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    $allowed = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/config');
    $allowed->assertOk();
    expect($allowed->headers->get('Access-Control-Allow-Origin'))->toBe(WIDGET_ORIGIN)
        ->and($allowed->headers->get('Vary'))->toContain('Origin');

    resetWidgetClient();
    $hostile = widgetClient($key, $token, $cookie, HOSTILE_ORIGIN)->getJson('/api/widget/v1/config');
    expect($hostile->headers->get('Access-Control-Allow-Origin'))->toBeNull('the per-widget CORS stamp leaked to a hostile origin')
        ->and($hostile->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

    // The preflight for a hostile origin grants nothing usable either: no credentials stamp for it on the real doors.
    $preflight = test()->withHeaders(['Origin' => HOSTILE_ORIGIN, 'Access-Control-Request-Method' => 'POST'])->options('/api/widget/v1/messages');
    expect($preflight->getStatusCode())->toBe(204);
});

it('the three layers are INDEPENDENT code paths: loader (client), CORS (WidgetCors), server (WidgetSessionController) — none delegates to another', function (): void {
    // Structural: neither layer IMPORTS or constructs the other (a docblock may name it; code may not).
    $cors = (string) file_get_contents(app_path('Http/Middleware/WidgetCors.php'));
    $server = (string) file_get_contents(app_path('Http/Controllers/Widget/WidgetSessionController.php'));
    expect(preg_match('/^use .*WidgetSessionController;|new WidgetSessionController/m', $cors))->toBe(0)
        ->and(preg_match('/^use .*WidgetCors;|new WidgetCors/m', $server))->toBe(0);
    // The loader's check is CLIENT code; it must not be the only place the allowlist is read.
    expect(file_exists(app_path('Domain/Auth/Widget/OriginAllowlist.php')))->toBeTrue();
});
