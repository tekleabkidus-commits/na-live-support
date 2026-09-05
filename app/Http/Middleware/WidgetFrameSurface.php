<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Widget\OriginAllowlist;
use App\Models\Widget;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc 13 P-FRAME-ANCESTORS (countersigned; Doc 02 FR-21/EC-08's structural
 * half; Doc 02 T-07's widget-frame clause): the panel DOCUMENT may be framed
 * ONLY by the resolved widget's allowed origins. This middleware runs INSIDE
 * the one resolver (`tenant:widget_key`), reads the widget the key names, and
 * places its allowlist on the request as CSP source expressions — an exact
 * origin verbatim, a `*.brand.example` wildcard as `https://*.brand.example`
 * (the CSP host-source form; the dot-boundary rule EC-12 pins lives in
 * OriginAllowlist and is the same boundary CSP applies). SecurityHeaders alone
 * turns the sources into the directive (the static sweep asserts that word
 * appears in exactly one place). A soft-deleted or unknown widget places an
 * EMPTY list: the document (already a 404) frames nowhere.
 */
final class WidgetFrameSurface
{
    /** @param  Closure(Request): Response  $next */
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $mode  `widget` (default): the widget's allowlist frames the panel document;
     *                        `self` (S13 step 11, the Designer's preview): the platform's OWN pages frame
     *                        the preview document — a staff-authenticated route only. This middleware stays
     *                        the ONE setter of the attribute (P-FRAME-ANCESTORS condition 1); the mode is
     *                        a route declaration, never a request input.
     */
    public function handle(Request $request, Closure $next, string $mode = 'widget'): Response
    {
        if ($mode === 'self') {
            $request->attributes->set(SecurityHeaders::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE, ["'self'"]);

            return $next($request);
        }
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);
        $widget = is_string($keyId) ? Widget::query()->where('key_id', $keyId)->first() : null;
        $origins = $widget instanceof Widget && $widget->deleted_at === null ? $widget->allowed_origins : [];

        $request->attributes->set(SecurityHeaders::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE, self::sources($origins));

        return $next($request);
    }

    /**
     * @param  list<string>  $origins
     * @return list<string>
     */
    public static function sources(array $origins): array
    {
        $sources = [];
        foreach ($origins as $origin) {
            $origin = trim($origin);
            if ($origin === '') {
                continue;
            }
            if (str_starts_with($origin, '*.')) {
                $sources[] = 'https://'.$origin;
            } elseif (preg_match('~^https://[A-Za-z0-9.-]+(?::\d+)?$~', $origin) === 1) {
                $sources[] = $origin;
            }
            // anything else (http, a path, a malformed entry) admits nothing — a stricter policy, never a broken header
        }

        return array_values(array_unique($sources));
    }
}
