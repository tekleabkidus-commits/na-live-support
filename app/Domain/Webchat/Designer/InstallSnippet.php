<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Designer;

use App\Models\Widget;
use App\Support\Settings\SettingsRepository;

/**
 * Doc 13 §4.4 Install / SEC-07 / Doc 02 T-07 (S13 step 10): the snippet and the two CSP
 * directives, computed from the PLATFORM's configured origins (D13-D02: never the
 * fixture hostnames the frozen file draws). The host page owns only these two lines;
 * everything else (HSTS, frame-ancestors on the frame, nosniff) is sent by our origins.
 */
final class InstallSnippet
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The shim is addressed by the FULL widget key (Doc 02 FR-21: the key + the origin mint the
     * session; the allowlist is the control, the key is a public identifier in every host page).
     * The issuer stores only its hash; RIDER 2 keeps the PUBLIC key on the widget row (written once,
     * UPDATE app-denied) so the drawn snippet renders at any time. A pre-rider row without it answers
     * `snippet: null, key_shown_once: true`.
     *
     * @return array{snippet: ?string, key_shown_once: bool, script_src: string, frame_src: string, csp: string, key_prefix: string}
     */
    public function forWidget(Widget $widget, ?string $keyPlaintext = null): array
    {
        $url = config('app.url');
        $platform = rtrim(is_string($url) ? $url : '', '/');
        $cdn = $this->origin('widget.cdn_origin', $platform);
        $frame = $this->origin('widget.frame_origin', $platform);
        $prefix = $widget->key->prefix ?? '';

        // RIDER 2: the public key on the row (written once at creation) renders the drawn snippet at any time;
        // `key_shown_once` stays true only for a pre-rider widget whose row never carried it.
        $stored = $widget->key_public;
        $key = $keyPlaintext ?? (is_string($stored) && $stored !== '' ? $stored : null);

        return [
            'snippet' => $key !== null ? '<script async src="'.$cdn.'/w/'.$key.'.js"></script>' : null,
            'key_shown_once' => $key === null,
            'script_src' => $cdn,
            'frame_src' => $frame,
            'csp' => "Content-Security-Policy:\n  script-src {$cdn};\n  frame-src  {$frame};",
            'key_prefix' => $prefix,
        ];
    }

    private function origin(string $key, string $fallback): string
    {
        $value = $this->settings->get($key);

        return is_string($value) && $value !== '' ? rtrim($value, '/') : $fallback;
    }
}
