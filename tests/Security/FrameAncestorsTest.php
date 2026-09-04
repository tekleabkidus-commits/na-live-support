<?php

declare(strict_types=1);

use App\Http\Middleware\WidgetFrameSurface;

/*
|--------------------------------------------------------------------------
| Doc 13 P-FRAME-ANCESTORS (countersign condition): the directive is set in
| EXACTLY ONE place — a static sweep over app/ finds the literal in
| SecurityHeaders and nowhere else — with a NEGATIVE CONTROL proving the
| sweep can see a second occurrence. Plus the source mapping (EC-12's
| boundary in CSP form) and the two surfaces' headers on real requests.
|--------------------------------------------------------------------------
*/

/** @return list<string> files under $dir whose CODE (never a comment) carries the directive literal */
function frameAncestorsSweep(string $dir): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        // CODE only — a comment that mentions the directive is not a writer (Doc 02's session
        // controller and the shim controller both discuss it in their docblocks).
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text = is_array($token) ? $token[1] : $token;
            if (str_contains($text, 'frame-ancestors')) {
                $hits[] = str_replace($dir.'/', '', $file->getPathname());
                break;
            }
        }
    }
    sort($hits);

    return $hits;
}

it('static sweep: the frame-ancestors directive is written in exactly ONE place (SecurityHeaders); the negative control plants a second file and the same sweep finds it', function (): void {
    expect(frameAncestorsSweep(app_path()))->toBe(['Http/Middleware/SecurityHeaders.php']);

    $dir = sys_get_temp_dir().'/fa-sweep-'.bin2hex(random_bytes(6));
    mkdir($dir.'/Http/Middleware', 0777, true);
    copy(app_path('Http/Middleware/SecurityHeaders.php'), $dir.'/Http/Middleware/SecurityHeaders.php');
    file_put_contents($dir.'/Planted.php', "<?php header('Content-Security-Policy: frame-ancestors *'); // a second WRITER (code, not a comment)");
    try {
        expect(frameAncestorsSweep($dir))->toBe(['Http/Middleware/SecurityHeaders.php', 'Planted.php'], 'the sweep is blind to a planted second writer');
    } finally {
        array_map('unlink', glob($dir.'/Http/Middleware/*') ?: []);
        @unlink($dir.'/Planted.php');
        @rmdir($dir.'/Http/Middleware');
        @rmdir($dir.'/Http');
        @rmdir($dir);
    }
});

/** @return list<string> files under $dir whose CODE (never a comment) satisfies $predicate */
function codeSweep(string $dir, callable $predicate): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        if ($predicate($code)) {
            $hits[] = str_replace($dir.'/', '', $file->getPathname());
        }
    }
    sort($hits);

    return $hits;
}

it('countersign condition 1 — the ESCAPE HATCH is the attribute: REQUEST_ANCESTOR_SOURCES_ATTRIBUTE is SET in exactly ONE place (WidgetFrameSurface) and referenced by exactly one other (its declaration + read in SecurityHeaders); the negative control plants a second setter as code and the sweep finds it', function (): void {
    $references = static fn (string $code): bool => str_contains($code, 'REQUEST_ANCESTOR_SOURCES_ATTRIBUTE');
    $setters = static fn (string $code): bool => preg_match('/attributes->set\(\s*\S*SecurityHeaders::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE/', $code) === 1;
    expect(codeSweep(app_path(), $references))->toBe(['Http/Middleware/SecurityHeaders.php', 'Http/Middleware/WidgetFrameSurface.php'])
        ->and(codeSweep(app_path(), $setters))->toBe(['Http/Middleware/WidgetFrameSurface.php']);

    $dir = sys_get_temp_dir().'/fa-attr-'.bin2hex(random_bytes(6));
    mkdir($dir.'/Http/Middleware', 0777, true);
    copy(app_path('Http/Middleware/WidgetFrameSurface.php'), $dir.'/Http/Middleware/WidgetFrameSurface.php');
    file_put_contents($dir.'/Planted.php', "<?php \$request->attributes->set(SecurityHeaders::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE, ['https://evil.example']); // a second SETTER, as code");
    try {
        expect(codeSweep($dir, $setters))->toBe(['Http/Middleware/WidgetFrameSurface.php', 'Planted.php'], 'the sweep is blind to a planted second setter');
    } finally {
        @unlink($dir.'/Http/Middleware/WidgetFrameSurface.php');
        @unlink($dir.'/Planted.php');
        @rmdir($dir.'/Http/Middleware');
        @rmdir($dir.'/Http');
        @rmdir($dir);
    }
});

it('source mapping: exact https origins verbatim, *.brand wildcards as https host-sources, everything else admits nothing; an empty allowlist frames nowhere', function (): void {
    expect(WidgetFrameSurface::sources(['https://help.fixture-operator.example', '*.fixture-brand.example', 'http://insecure.example', 'https://x.example/path', ' ', 'https://help.fixture-operator.example']))
        ->toBe(['https://help.fixture-operator.example', 'https://*.fixture-brand.example'])
        ->and(WidgetFrameSurface::sources([]))->toBe([]);
});

it('surfaces on real requests: the panel document frames ONLY its widget\'s allowlist (wildcard included); the staff root and a soft-deleted widget\'s 404 frame nowhere', function (): void {
    [$tenant, $widget, $key] = widgetFixture(['https://help.fixture-operator.example', '*.fixture-brand.example']);
    $panel = $this->get('/w/panel/'.$key)->assertStatus(200);
    $csp = (string) $panel->headers->get('Content-Security-Policy');
    expect($csp)->toContain('frame-ancestors https://help.fixture-operator.example https://*.fixture-brand.example')
        ->and($csp)->not->toContain("frame-ancestors 'none'")
        ->and($panel->headers->has('X-Frame-Options'))->toBeFalse('condition 2: a non-empty list carries NO X-Frame-Options — the header cannot express a list, the CSP is the braces');

    $root = $this->get('/');
    expect((string) $root->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($root->headers->get('X-Frame-Options'))->toBe('DENY');

    // An EMPTY allowlist: 'none' AND the belt stays on.
    [, , $emptyKey] = widgetFixture([]);
    $empty = $this->get('/w/panel/'.$emptyKey)->assertStatus(200);
    expect((string) $empty->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($empty->headers->get('X-Frame-Options'))->toBe('DENY');

    bindTenant($tenant);
    Illuminate\Support\Facades\DB::table('widgets')->where('id', $widget->getKey())->update(['deleted_at' => Illuminate\Support\Facades\DB::raw('clock_timestamp()')]);
    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();
    $dead = $this->get('/w/panel/'.$key);
    $dead->assertStatus(404);
    expect((string) $dead->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($dead->headers->get('X-Frame-Options'))->toBe('DENY');
});
