<?php

declare(strict_types=1);

use App\Domain\Webchat\Bundle\WidgetBundleResolver;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 13 T-01 / FR-01 / FR-03 / FR-04 (S13 step 6): the two key-addressed
| documents. The shim wraps the built loader with the config the loader's
| client-side origin layer reads (the allowlist), the bundle pointer and the
| origins; the panel document is a nonce'd module-script mount point. C3: a
| soft-deleted widget is dead to the wire (both doors 404); a suspended tenant
| is refused by `tenant.state:outbound` before the controller runs.
|--------------------------------------------------------------------------
*/

it('GET /w/{key}.js: the shim wraps the BUILT loader with __NLW_CFG (allowlist, widget id, origins, bundle pointer, appearance) — javascript, 60 s public cache, no secret material', function (): void {
    [$tenant, $widget, $key] = widgetFixture(['https://help.fixture-operator.example', '*.fixture-brand.example']);

    $response = $this->get('/w/'.$key.'.js');
    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'max-age=60, public');
    $body = (string) $response->getContent();
    expect($body)->toStartWith(';(function(){var __NLW_CFG=')
        ->and($body)->toContain('"allowed_origins":["https://help.fixture-operator.example","*.fixture-brand.example"]')
        ->and($body)->toContain('"widget_id":"'.(string) $widget->getKey().'"')
        ->and($body)->toContain('"widget_key":"'.$key.'"')
        ->and($body)->toContain('"platform_origin":"https://localhost"')
        ->and($body)->toContain('"position":"bottom_right"')
        ->and($body)->toContain('PlatformChat')   // the loader's own API global — the built file is inside the wrapper
        ->and($body)->not->toContain('vault://')->not->toContain('identity_secret');
    // The pointer names the current build (no ref rows on a fresh install — the manifest is the pointer).
    $manifest = app(WidgetBundleResolver::class)->manifest();
    expect($body)->toContain('"bundle":{"panel":"'.$manifest['panel'].'"')
        ->and($response->headers->get('X-Widget-Bundle'))->toBe(WidgetBundleResolver::hashOf($manifest['panel']));
});

it('GET /w/panel/{key}: the panel document is a nonce\'d module-script mount point under SecurityHeaders\' CSP whose frame-ancestors IS the widget\'s allowlist (P-FRAME-ANCESTORS); no-store', function (): void {
    [, $widget, $key] = widgetFixture();

    $response = $this->get('/w/panel/'.$key.'?frame=launcher');
    $response->assertStatus(200)->assertHeader('Cache-Control', 'no-store, private');
    $html = (string) $response->getContent();
    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $m);
    expect($m[1] ?? '')->not->toBe('')
        ->and($html)->toContain('<script type="module" nonce="'.$m[1].'" src="/w/panel.')
        ->and($html)->toContain('<div id="nlw-root" data-widget="'.(string) $widget->getKey().'"></div>')
        ->and($csp)->toContain('frame-ancestors https://help.fixture-operator.example')   // P-FRAME-ANCESTORS: the widget's allowlist, nothing else
        ->and($csp)->not->toContain('unsafe-inline')->not->toContain('unsafe-eval');
});

it('C3: a soft-deleted widget is dead to the wire — shim and panel both answer an EMPTY 404; an unknown key is 404; a suspended tenant is refused (never a 200 script)', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    bindTenant($tenant);
    DB::table('widgets')->where('id', (string) $widget->getKey())->update(['deleted_at' => DB::raw('clock_timestamp()')]);
    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();

    $this->get('/w/'.$key.'.js')->assertStatus(404)->assertSee('', false);
    expect((string) $this->get('/w/'.$key.'.js')->getContent())->toBe('');
    $this->get('/w/panel/'.$key)->assertStatus(404);
    $this->get('/w/fixture.nosuchkey.js')->assertStatus(404);

    [$tenant2, , $key2] = widgetFixture();
    suspendTenant($tenant2);
    $refused = $this->get('/w/'.$key2.'.js');
    expect($refused->getStatusCode())->not->toBe(200)
        ->and((string) $refused->getContent())->not->toContain('__NLW_CFG');
});
