<?php

declare(strict_types=1);

use App\Domain\Auth\Widget\VisitorTokenStore;
use App\Models\Widget;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Route;

/**
 * FR-21 issuance + AC-06's two independent layers + EC-08 (Doc 02 §4.6, §18).
 *
 * THE POSITIVE CONTROL COMES FIRST, by protocol: every refusal in this file is
 * meaningful only because the first test proves an allowed origin genuinely
 * receives a working session — the AC-01 lesson, recorded in EnumerationTest.
 *
 * AC-06's INDEPENDENCE clause is proven by removal, not by inspection: the
 * server-layer test runs on a test-mounted route with NO WidgetCors in the
 * stack; the CORS-layer test drives WidgetCors around a $next that happily
 * returns 200, simulating a deleted server check. Each layer refuses alone.
 *
 * EC-08 honesty (owner ruling, step 10): the ancestor list is CLIENT-SUPPLIED
 * (the loader's location.ancestorOrigins), so against a non-browser caller it
 * is advisory — a liar gets a session for its own visitor and nothing else.
 * The structural half of EC-08 is Doc 13's frame-ancestors CSP on the real
 * frame; the traceability row stays PARTIAL until that exists.
 */
// WIDGET_ORIGIN and the widgetFixture/widgetSession/widgetContext/
// visitorCookieFrom helpers live in tests/Pest.php — shared with
// WidgetTokenTheftTest and WidgetPartitionTest, and documented there.

it('issues a working visitor session to an allowed origin (FR-21 — the positive control)', function (): void {
    [$tenant, $widget, $key] = widgetFixture();

    $response = widgetSession($key)->assertCreated();

    // The token: opaque, 128-bit, prefixed for SEC-08's scrubber.
    $token = $response->json('token');
    expect($token)->toMatch('/^nvt_[0-9a-f]{32}$/');
    expect($response->json('visitor_id'))->toBeUuid();
    expect($response->json('expires_in'))->toBe(VisitorTokenStore::TTL_SECONDS);

    // The FR-22 cookie, with BR-06's separate scope on its face.
    $cookieHeader = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'nlw_visitor');
    expect($cookieHeader)->not->toBeNull();
    expect($cookieHeader->getPath())->toBe('/api/widget');
    expect($cookieHeader->isHttpOnly())->toBeTrue();
    expect($cookieHeader->isSecure())->toBeTrue();
    expect($cookieHeader->getSameSite())->toBe('none');

    // The CORS layer's grant, present exactly because the origin is allowed.
    $response->assertHeader('Access-Control-Allow-Origin', WIDGET_ORIGIN);
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');

    // FR-21's binding: the Redis record carries {tenant, widget, visitor}.
    $record = app(VisitorTokenStore::class)->find($tenant->id, $token);
    expect($record)->not->toBeNull();
    expect($record->widgetId)->toBe($widget->id);
    expect($record->visitorId)->toBe($response->json('visitor_id'));

    // And the session actually WORKS: token+cookie authenticate the surface.
    widgetContext($key, $token, visitorCookieFrom($response))
        ->assertOk()
        ->assertJsonPath('resolved_tenant_id', $tenant->id)
        ->assertJsonPath('resolution_source', 'widget_key');
});

it('slides the 30-day TTL on every authenticated touch (FR-21 "sliding")', function (): void {
    [$tenant, , $key] = widgetFixture();

    $issue = widgetSession($key)->assertCreated();
    $token = $issue->json('token');
    $cookie = visitorCookieFrom($issue);

    // Age the key at the Redis layer (a slide cannot be proven by waiting,
    // and CLAUDE.md forbids wall-clock tests): shrink the TTL, touch, and the
    // window must be re-armed to the full 30 days.
    $store = app(VisitorTokenStore::class);
    $redisKey = sprintf('t:%s:widget-visitor:%s', $tenant->id, hash('sha256', $token));
    app(RedisFactory::class)->connection('volatile')->expire($redisKey, 3600);
    expect($store->ttlSeconds($tenant->id, $token))->toBeLessThanOrEqual(3600);

    widgetContext($key, $token, $cookie)->assertOk();

    expect($store->ttlSeconds($tenant->id, $token))
        ->toBeGreaterThan(VisitorTokenStore::TTL_SECONDS - 60);
});

it('refuses a disallowed origin at the server layer, issuing NOTHING (FR-21, AC-06)', function (): void {
    [, , $key] = widgetFixture();

    $response = widgetSession($key, headers: ['Origin' => 'https://hostile.example'])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'origin_not_allowed');

    // Nothing issued: no token in the body, no cookie on the response, and —
    // the CORS layer agreeing independently — no browser-readable grant.
    expect($response->json('token'))->toBeNull();
    expect($response->baseResponse->headers->getCookies())->toBeEmpty();
    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('refuses at the server layer with the CORS layer REMOVED — AC-06 independence, direction one', function (): void {
    // A route carrying the real binder and controller but NO WidgetCors: the
    // refusal must not be the CORS middleware's doing.
    Route::middleware(['tenant:widget_key', 'tenant.state:inbound'])
        ->post('api/widget-test/no-cors/session', [App\Http\Controllers\Widget\WidgetSessionController::class, 'store']);

    [, , $key] = widgetFixture();

    app('auth')->forgetGuards();
    test()->postJson('https://localhost/api/widget-test/no-cors/session', [], [
        'Authorization' => 'Bearer '.$key,
        'Origin' => 'https://hostile.example',
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'origin_not_allowed');

    // Positive control on the same sabotaged stack: the allowed origin still
    // gets its session, so the 403 above is the allowlist speaking, not a
    // broken route.
    app('auth')->forgetGuards();
    test()->postJson('https://localhost/api/widget-test/no-cors/session', [], [
        'Authorization' => 'Bearer '.$key,
        'Origin' => WIDGET_ORIGIN,
    ])->assertCreated();
});

it('withholds the CORS grant even when the server layer waves everything through — AC-06 independence, direction two', function (): void {
    // WidgetCors driven directly, around a $next that returns 200 no matter
    // what — a stand-in for a deleted server check. The disallowed origin's
    // response must carry no Access-Control-Allow-Origin: the browser would
    // refuse to hand it to the page, which is the CORS layer refusing alone.
    [$tenant, $widget] = widgetFixture();

    $stampFor = function (string $origin) use ($tenant, $widget): ?string {
        $request = Illuminate\Http\Request::create('https://localhost/api/widget/v1/session', 'POST');
        $request->headers->set('Origin', $origin);
        $request->attributes->set(
            App\Http\Middleware\ResolveTenant::KEY_ID_ATTRIBUTE,
            $widget->key_id,
        );

        return asTenant($tenant, function () use ($request): ?string {
            $response = app(App\Http\Middleware\WidgetCors::class)->handle(
                $request,
                fn (): Symfony\Component\HttpFoundation\Response => new Symfony\Component\HttpFoundation\Response('ok', 200),
            );

            return $response->headers->get('Access-Control-Allow-Origin');
        });
    };

    expect($stampFor(WIDGET_ORIGIN))->toBe(WIDGET_ORIGIN);      // positive control
    expect($stampFor('https://hostile.example'))->toBeNull();   // the layer refusing alone
});

it('refuses an allowed origin wrapped by a disallowed ancestor (EC-08)', function (): void {
    [, , $key] = widgetFixture();

    // Positive control: the same origin with an ALLOWED ancestor chain works.
    widgetSession($key, body: ['ancestors' => [WIDGET_ORIGIN]])->assertCreated();

    widgetSession($key, body: ['ancestors' => [WIDGET_ORIGIN, 'https://hostile-wrapper.example']])
        ->assertForbidden()
        ->assertJsonPath('code', 'origin_not_allowed');
});

it('treats a top-level page (no ancestors) as allowed (EC-08)', function (): void {
    [, , $key] = widgetFixture();

    widgetSession($key, body: ['ancestors' => []])->assertCreated();
});

it('refuses when the Origin header and the declared origin disagree', function (): void {
    [, , $key] = widgetFixture();

    widgetSession($key, body: ['origin' => 'https://hostile.example'])
        ->assertForbidden()
        ->assertJsonPath('code', 'origin_not_allowed');
});

it('judges a non-browser caller by its declared origin — no Origin header, still the server layer', function (): void {
    [, , $key] = widgetFixture();

    // curl-shaped: no Origin header at all. Declared origin allowed → session;
    // declared origin hostile → refused; nothing declared → refused.
    app('auth')->forgetGuards();
    test()->postJson('https://localhost/api/widget/v1/session', ['origin' => WIDGET_ORIGIN], [
        'Authorization' => 'Bearer '.$key,
    ])->assertCreated();

    app('auth')->forgetGuards();
    test()->postJson('https://localhost/api/widget/v1/session', ['origin' => 'https://hostile.example'], [
        'Authorization' => 'Bearer '.$key,
    ])->assertForbidden();

    app('auth')->forgetGuards();
    test()->postJson('https://localhost/api/widget/v1/session', [], [
        'Authorization' => 'Bearer '.$key,
    ])->assertForbidden()->assertJsonPath('code', 'origin_not_allowed');
});

it('pins the wildcard dot boundary: *.brand.com never admits evil-brand.com (ruling D-5, Doc 13 EC-12)', function (): void {
    [, , $key] = widgetFixture(origins: ['*.fixture-brand.example']);

    // Positive controls: one and many labels under the wildcard.
    widgetSession($key, headers: ['Origin' => 'https://help.fixture-brand.example'])->assertCreated();
    widgetSession($key, headers: ['Origin' => 'https://a.b.fixture-brand.example'])->assertCreated();

    // The hostile fixture, mandatory by ruling: suffix-without-dot must lose.
    widgetSession($key, headers: ['Origin' => 'https://evil-fixture-brand.example'])->assertForbidden();

    // The bare apex is NOT under the wildcard either.
    widgetSession($key, headers: ['Origin' => 'https://fixture-brand.example'])->assertForbidden();
});

it('compares ports exactly and refuses http origins outright (ruling D-5)', function (): void {
    [, , $key] = widgetFixture(origins: [WIDGET_ORIGIN, 'https://app.fixture-operator.example:8443']);

    widgetSession($key, headers: ['Origin' => 'https://app.fixture-operator.example:8443'])->assertCreated();
    widgetSession($key, headers: ['Origin' => 'https://app.fixture-operator.example'])->assertForbidden();
    widgetSession($key, headers: ['Origin' => WIDGET_ORIGIN.':444'])->assertForbidden();
    widgetSession($key, headers: ['Origin' => 'http://help.fixture-operator.example'])->assertForbidden();

    // Case-insensitive host comparison — same origin, shouting.
    widgetSession($key, headers: ['Origin' => 'https://HELP.Fixture-Operator.EXAMPLE'])->assertCreated();
});

it('refuses a widget key with no widget record — fail-closed, same shape as any refusal', function (): void {
    [, , $key] = widgetFixture(withWidgetRecord: false);

    widgetSession($key)
        ->assertForbidden()
        ->assertJsonPath('code', 'origin_not_allowed');
});

it('resolves nothing for an unknown widget key — 404, never 403 (C-10)', function (): void {
    widgetFixture(); // a real tenant exists; the key below still matches nothing

    app('auth')->forgetGuards();
    test()->postJson('https://localhost/api/widget/v1/session', [], [
        'Authorization' => 'Bearer '.str_repeat('ab', 6).'.'.str_repeat('cd', 32),
        'Origin' => WIDGET_ORIGIN,
    ])->assertNotFound();
});

it('answers a genuine preflight permissively and a bare OPTIONS with nothing (T-06)', function (): void {
    // Preflight: reflected origin + the headers the widget client sends. The
    // reflection grants nothing — see WidgetCors's docblock — and both
    // enforcement layers meet the actual request.
    $preflight = test()->call('OPTIONS', 'https://localhost/api/widget/v1/session', server: [
        'HTTP_ORIGIN' => 'https://anything-at-all.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $preflight->assertNoContent();
    $preflight->assertHeader('Access-Control-Allow-Origin', 'https://anything-at-all.example');
    expect($preflight->headers->get('Access-Control-Allow-Headers'))
        ->toContain('X-Tenant-Key')
        ->toContain('X-Visitor-Token');

    // A bare OPTIONS (no Access-Control-Request-Method) is not a preflight:
    // 204, no CORS grant.
    $bare = test()->call('OPTIONS', 'https://localhost/api/widget/v1/session');
    $bare->assertNoContent();
    expect($bare->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('keeps two tenants\' widgets invisible to each other (N-01, the standing check)', function (): void {
    [$tenantA, $widgetA] = widgetFixture();
    [$tenantB] = widgetFixture();

    $seenFromB = asTenant($tenantB, fn () => Widget::query()->count());
    expect($seenFromB)->toBe(1);

    $idsFromB = asTenant($tenantB, fn () => Widget::query()->pluck('id')->all());
    expect($idsFromB)->not->toContain($widgetA->id);
});
