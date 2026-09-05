<?php

declare(strict_types=1);

use App\Models\Widget;

/*
|--------------------------------------------------------------------------
| Doc 13 NFR-01 — the LOADER-DRAWN LAUNCHER (advisor ruling 2026-09-05, the
| launcher-paint miss: 872 ms vs ≤ 300 ms on wall power). `GET /w/panel/{key}
| ?frame=launcher` is a minimal, DATA-FREE document: it paints the labeled
| bubble without fetching the core bundle (no external script tag at all), it
| carries both launch languages' launcher strings so the accessible name is
| right from the first paint, its tokens come from the frozen design-token
| source, it is ≤ 2 KB gzipped on its own (a served resource — the advisor's
| re-set bound; it does not count against the loader's 5 KB), and it is served no-store under the widget
| frame surface (frame-ancestors = the allowlist — FrameAncestorsTest).
| The panel document (`?frame=panel` / no frame) is unchanged (WidgetShimTest).
*/

function launcherScene(): array
{
    [, $widget, $key] = widgetFixture(['https://help.fixture-operator.example']);

    return [$key, $widget];
}

it('NFR-01: the launcher document paints the labeled bubble WITHOUT the core — no external script, the button + label from the i18n keys, both languages embedded, tokens from the frozen source, ≤ 1 KB gz, no-store', function (): void {
    [$key, $widget] = launcherScene();
    $response = $this->get('/w/panel/'.$key.'?frame=launcher');
    $response->assertStatus(200)->assertHeader('Cache-Control', 'no-store, private');
    $html = $response->getContent();

    // DATA-FREE: nothing to fetch before the bubble paints — no <script src>, no stylesheet link, no <script type="module">.
    expect($html)->not->toMatch('/<script[^>]*\ssrc=/')
        ->and($html)->not->toContain('<script type="module"')
        ->and($html)->not->toContain('<link rel="stylesheet"')
        // the bubble, labeled from `widget.launcher.open`, inside the mount point the core later takes over
        ->and($html)->toContain('<button type="button" id="nlw-static" class="nlw-launcher" data-open="false" aria-label="'.trans('widget.launcher.open', [], 'en').'"')
        ->and($html)->toContain('<div id="nlw-root" data-widget="'.(string) $widget->getKey().'"')
        // both launch languages' strings ride along (the persisted visitor choice wins client-side; N-03: keys, not literals)
        ->and($html)->toContain(json_encode(trans('widget.launcher.open', [], 'am'), JSON_UNESCAPED_UNICODE))
        ->and($html)->toContain(json_encode(trans('widget.launcher.close', [], 'en'), JSON_UNESCAPED_UNICODE))
        // tokens: the launcher's variables come from the frozen source, light AND dark colourways
        ->and($html)->toContain(':root{--ds-accent:')
        ->and($html)->toContain('[data-theme=dark]{--ds-accent:')
        ->and($html)->not->toContain('%TOKENS_')
        // the core boots INTO this document later — its files are named for the boot-core step, never fetched now
        ->and($html)->toMatch('/C="\/w\/panel\.[A-Za-z0-9_-]+\.js"/')
        // the nonce'd inline style + script are the document's only code (SecurityHeaders' CSP admits them)
        ->and(preg_match_all('/<(?:style|script) nonce="([^"]+)"/', $html, $m))->toBe(2)   // the two inline tags
        ->and($m[1][0])->toBe($m[1][1])
        ->and(preg_match_all('/nonce="([^"]+)"/', $html, $all))->toBe(3)               // + the boot script's own `s.nonce=` for the core it injects
        ->and(count(array_unique($all[1])))->toBe(1);
    $gz = strlen((string) gzencode($html, 9));
    // the SERVED document is its own resource (advisor ruling 2026-09-05): ≤ 2 KB gz on its own; it does not count against the loader's 5 KB
    expect($gz)->toBeLessThanOrEqual(2048, sprintf('the served launcher document must stay ≤ 2 KB gz — measured %d B gz, %d B raw', $gz, strlen($html)));
});

it('NFR-01: the panel document (no frame / frame=panel) is unchanged — the nonce\'d module-script mount point', function (): void {
    [$key] = launcherScene();
    foreach (['', '?frame=panel'] as $q) {
        $html = $this->get('/w/panel/'.$key.$q)->assertStatus(200)->getContent();
        expect($html)->toContain('<script type="module" nonce=')
            ->and($html)->not->toContain('id="nlw-static"');
    }
});
