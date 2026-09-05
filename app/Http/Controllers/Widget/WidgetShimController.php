<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Webchat\Bundle\WidgetBundleResolver;
use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Widget;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Doc 13 T-01 / FR-01 / FR-03 / FR-04 — the two documents the host page and
 * the iframes fetch by WIDGET KEY (the path; WidgetKeyFromPath hands it to the
 * one resolver):
 *
 *   GET /w/{widget_key}.js       the SHIM — `;(function(){var __NLW_CFG={…}; <loader> })();`
 *                                60 s cache (T-01: CDN shims are 60 s, immutables long-cache).
 *                                The config carries the allowlist (FR-03's CLIENT layer reads
 *                                it), the bundle pointer (FR-04), the frame/platform origins and
 *                                the appearance/proactive dials — never a secret beyond the
 *                                widget key the page already holds.
 *   GET /w/panel/{widget_key}    the PANEL DOCUMENT — a nonce'd module script (SecurityHeaders
 *                                owns the CSP; the panel's ancestor allowlist arrives through
 *                                WidgetFrameSurface — P-FRAME-ANCESTORS).
 *
 * C3 (countersign): a soft-deleted widget is dead to the wire — both doors 404
 * (an empty 404 to a <script>/<iframe> is silence, never a broken host page).
 * A suspended tenant is refused by `tenant.state:outbound` before this runs.
 */
final class WidgetShimController
{
    public function __construct(
        private readonly WidgetBundleResolver $bundles,
        private readonly WidgetConfigReader $config,
        private readonly SettingsRepository $settings,
    ) {}

    public function script(Request $request, string $widget_key): Response
    {
        $widget = $this->widget($request);
        if ($widget === null) {
            return $this->silent404();
        }
        $bundle = $this->bundles->forWidget($widget);
        $loaderPath = public_path('w/'.$bundle['loader']);
        $loader = is_file($loaderPath) ? file_get_contents($loaderPath) : false;
        if ($loader === false) {
            return $this->silent404();
        }
        $live = $this->config->live($widget)['config'];
        /** @var array{position?: string, offset?: array{side?: int, bottom?: int}} $appearance */
        $appearance = is_array($live['appearance'] ?? null) ? $live['appearance'] : [];
        /** @var array{proactive?: list<array<string, mixed>>} $behavior */
        $behavior = is_array($live['behavior'] ?? null) ? $live['behavior'] : [];
        $platform = $request->getSchemeAndHttpHost();
        $frameOrigin = $this->settings->get('widget.frame_origin');

        $cfg = [
            'widget_key' => $widget_key,
            'widget_id' => WidgetBundleResolver::id($widget),
            'platform_origin' => $platform,
            'frame_origin' => is_string($frameOrigin) && $frameOrigin !== '' ? $frameOrigin : $platform,
            'allowed_origins' => $widget->allowed_origins,
            'bundle' => ['panel' => $bundle['panel'], 'version' => $bundle['version']],
            'position' => $appearance['position'] ?? 'bottom_right',
            'offset' => ['side' => (int) ($appearance['offset']['side'] ?? 16), 'bottom' => (int) ($appearance['offset']['bottom'] ?? 20)],
            'proactive' => $behavior['proactive'] ?? [],

            // The copy the panel needs BEFORE any session exists (K03's last-resort card + its header), both languages.

            'strings' => ['en' => self::minimalStrings('en'), 'am' => self::minimalStrings('am')],
        ];
        $body = ';(function(){var __NLW_CFG='.json_encode($cfg, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP).";\n".$loader."\n})();\n";

        return new Response($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'X-Widget-Bundle' => $bundle['version'],
        ]);
    }

    public function panel(Request $request, string $widget_key): Response
    {
        $widget = $this->widget($request);
        if ($widget === null) {
            return $this->silent404();
        }
        $bundle = $this->bundles->forWidget($widget);
        $nonce = $request->attributes->get(SecurityHeaders::REQUEST_NONCE_ATTRIBUTE);
        $nonce = is_string($nonce) ? $nonce : '';
        if ($request->query('frame') === 'launcher') {
            return $this->launcherDocument($widget, $bundle, $nonce);
        }
        $src = '/w/'.$bundle['panel'];
        $links = '';
        foreach ($bundle['css'] as $css) {
            $links .= '<link rel="stylesheet" href="'.htmlspecialchars('/w/'.$css, ENT_QUOTES).'">';
        }
        // No literal copy: the document is a mount point; every visible string arrives through the config envelope (N-03).
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex"><meta name="color-scheme" content="light dark">'
            .$links
            .'<script type="module" nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'" src="'.htmlspecialchars($src, ENT_QUOTES).'"></script>'
            .'</head><body><div id="nlw-root" data-widget="'.htmlspecialchars(WidgetBundleResolver::id($widget), ENT_QUOTES).'"></div></body></html>';

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Widget-Bundle' => $bundle['version'],
        ]);
    }

    /**
     * NFR-01 (advisor ruling 2026-09-05, the launcher-paint miss): the LAUNCHER frame's document is
     * the LOADER-DRAWN LAUNCHER — a minimal, data-free document (tokens only, the labeled button + the
     * unread badge, ≤ 1 KB gz) that paints WITHOUT fetching the core bundle. The core boots into this
     * same document later (on first open or on idle after the paint) when the loader posts `boot-core`;
     * the panel entry then replaces the static button in place — same markup, same tokens, no flash.
     * Every visible string is an i18n key rendered server-side (N-03); the design tokens are read from
     * the frozen source `resources/css/design-tokens.css`, never typed here.
     *
     * @param  array{panel: string, css: list<string>, version: string}  $bundle
     */
    private function launcherDocument(Widget $widget, array $bundle, string $nonce): Response
    {
        $live = $this->config->live($widget)['config'];
        /** @var array<string, mixed> $appearance */
        $appearance = is_array($live['appearance'] ?? null) ? $live['appearance'] : [];
        /** @var array<string, mixed> $content */
        $content = is_array($live['content'] ?? null) ? $live['content'] : [];
        $language = is_string($content['default_language'] ?? null) && in_array($content['default_language'], ['en', 'am'], true) ? $content['default_language'] : 'en';
        $theme = is_string($appearance['theme'] ?? null) && in_array($appearance['theme'], ['light', 'dark', 'auto'], true) ? $appearance['theme'] : 'auto';
        $strings = [];
        foreach (['en', 'am'] as $lang) {
            $v = trans('widget.launcher', [], $lang);
            $strings[$lang] = is_array($v) ? $v : [];
        }
        $tokens = self::designTokens();
        $brand = is_array($appearance['brand'] ?? null) && is_string($appearance['brand']['accent'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $appearance['brand']['accent']) === 1
            ? 'html{--ds-accent:'.$appearance['brand']['accent'].';--ds-accent-contrast:'.self::accentContrast($appearance['brand']['accent'], $tokens).'}'
            : '';
        $label = $strings[$language]['open'] ?? '';
        $json = static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $template = (string) file_get_contents(base_path('widget/launcher/launcher.html'));
        $html = strtr($template, [
            '%LANG%' => $language,
            '%NONCE%' => htmlspecialchars($nonce, ENT_QUOTES),
            '%THEME%' => $theme,
            '%WIDGET%' => htmlspecialchars(WidgetBundleResolver::id($widget), ENT_QUOTES),
            '%LABEL%' => htmlspecialchars(is_string($label) ? $label : '', ENT_QUOTES),
            '%TOKENS_LIGHT%' => $tokens['light'],
            '%TOKENS_DARK%' => $tokens['dark'],
            '%BRAND%' => $brand,
            '%STRINGS%' => $json($strings),
            '%CORE_SRC%' => htmlspecialchars('/w/'.$bundle['panel'], ENT_QUOTES),
            '%CORE_CSS%' => $json(array_map(static fn (string $c): string => '/w/'.$c, $bundle['css'])),
        ]);

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Widget-Bundle' => $bundle['version'],
        ]);
    }

    /**
     * The launcher's token VALUES for both colourways, read from the frozen source file (the hex-extraction
     * script's own source), cached per process. Only the tokens the launcher rules use.
     *
     * @return array{light: string, dark: string, values: array<string, array<string, string>>}
     */
    private static function designTokens(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            /** @var array{light: string, dark: string, values: array<string, array<string, string>>} $cache */
            return $cache;
        }
        $css = (string) file_get_contents(base_path('resources/css/design-tokens.css'));
        $wanted = ['accent', 'accent-contrast', 'text-ink', 'danger-strong', 'focus-ring', 'panel-bg', 'text-primary'];
        $blocks = ['light' => '/:root\s*\{([^}]*)\}/s', 'dark' => "/\[data-theme='dark'\]\s*\{([^}]*)\}/s"];
        $out = ['light' => '', 'dark' => '', 'values' => []];
        foreach ($blocks as $name => $pattern) {
            $body = preg_match($pattern, $css, $m) === 1 ? $m[1] : '';
            $decl = '';
            foreach ($wanted as $token) {
                if (preg_match('/--ds-'.preg_quote($token, '/').':\s*([^;]+);/', $body, $v) === 1) {
                    $value = trim($v[1]);
                    $decl .= '--ds-'.$token.':'.$value.';';
                    $out['values'][$name][$token] = $value;
                }
            }
            $out[$name] = $decl;
        }
        $cache = $out;

        return $out;
    }

    /**
     * Mirrors the panel's theme.ts `accentContrast`: white or ink on the accent, whichever reads better —
     * both candidates are the design system's own accent-contrast tokens (light / dark colourway).
     *
     * @param  array{light: string, dark: string, values: array<string, array<string, string>>}  $tokens
     */
    private static function accentContrast(string $hex, array $tokens): string
    {
        $n = (int) hexdec(substr($hex, 1));
        $lin = static fn (int $c): float => ($c / 255) <= 0.03928 ? ($c / 255) / 12.92 : ((($c / 255) + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin(($n >> 16) & 255) + 0.7152 * $lin(($n >> 8) & 255) + 0.0722 * $lin($n & 255);
        $white = $tokens['values']['light']['accent-contrast'] ?? '';
        $ink = $tokens['values']['dark']['accent-contrast'] ?? '';

        return 1.05 / ($l + 0.05) >= ($l + 0.05) / 0.05 ? $white : $ink;
    }

    /** @return array<string, mixed> */
    private static function minimalStrings(string $language): array
    {
        $out = [];
        foreach (['retry', 'header', 'a11y'] as $branch) {
            $v = trans('widget.'.$branch, [], $language);
            if (is_array($v)) {
                $out[$branch] = $v;
            }
        }

        return $out;
    }

    private function widget(Request $request): ?Widget
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);
        if (! is_string($keyId)) {
            return null;
        }
        $widget = Widget::query()->where('key_id', $keyId)->first();

        return $widget instanceof Widget && $widget->deleted_at === null ? $widget : null;
    }

    private function silent404(): Response
    {
        return new Response('', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
