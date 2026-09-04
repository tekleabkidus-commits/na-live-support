<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-07: the security-header posture, applied everywhere (Doc 02 §10).
 *
 * One middleware owns every header so the posture cannot drift per-route. The CSP is
 * nonce-based with no unsafe-inline anywhere — an injected <script> without this
 * request's nonce does not execute, which is the whole point of CSP and the reason
 * 'unsafe-inline' would make the header decoration. The nonce is generated per
 * request and stored on the REQUEST (never the container: under Octane a container
 * instance binding would leak one request's nonce into the next, and a reused nonce
 * is a bypass, not a bug).
 *
 * Surfaces: `staff` (default) forbids framing entirely. The widget frame surface
 * (Doc 02 FR-21/EC-08, built in the widget step) swaps frame-ancestors for the
 * widget's per-tenant allowlist — everything else stays identical.
 *
 * HSTS is emitted unconditionally: user agents ignore it over plain HTTP by
 * specification, so there is no cleartext downgrade risk in sending it early, and
 * emitting it here means the TLS'd stack (SEC-06) needs no second change. Doc 18
 * FR-12 owns the load-balancer half in production; this is the application half.
 */
final class SecurityHeaders
{
    public const REQUEST_NONCE_ATTRIBUTE = 'csp_nonce';

    /**
     * Doc 13 (P-FRAME-ANCESTORS): the widget FRAME surface's ancestor sources,
     * placed on the REQUEST by the panel route's own middleware (the resolved
     * widget's allowlist — Doc 02 FR-21/EC-08's structural half). Read here
     * AFTER `$next` returns, so the group-appended instance sees it; the
     * attribute names sources, this class alone names the directive.
     *
     * @var string
     */
    public const REQUEST_ANCESTOR_SOURCES_ATTRIBUTE = 'csp_ancestor_sources';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $surface = 'staff'): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set(self::REQUEST_NONCE_ATTRIBUTE, $nonce);

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request, $nonce, $surface));
        $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Belt for pre-CSP user agents; frame-ancestors is the braces. The ONE exception
        // (P-FRAME-ANCESTORS, condition 2): a widget panel with a NON-EMPTY ancestor list —
        // X-Frame-Options cannot express a list, so the belt would contradict the braces;
        // the CSP alone speaks. An empty list, a 404, every other surface: DENY.
        $ancestors = $request->attributes->get(self::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE);
        if (! (is_array($ancestors) && $ancestors !== [])) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request, string $nonce, string $surface): string
    {
        $connect = ["'self'", ...$this->configuredOrigins('security.csp.connect_src')];
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'nonce-{$nonce}'"];

        // The Vite dev server serves scripts and a HMR websocket from its own origin.
        // Local only — the testing environment gets the production-shaped policy, so
        // the suite proves what production ships.
        if (app()->environment('local')) {
            $dev = $this->configuredOrigins('security.csp.dev_origins');
            $script = [...$script, ...$dev];
            $style = [...$style, ...$dev];
            $connect = [...$connect, ...$dev];
        }

        $sources = $request->attributes->get(self::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE);
        $frameAncestors = match (true) {
            // The widget FRAME surface (Doc 13 P-FRAME-ANCESTORS; Doc 02 FR-21/EC-08's
            // structural half): the resolved widget's allowlist, per request; an empty
            // allowlist frames nowhere. Every other surface forbids framing entirely.
            is_array($sources) => $sources === [] ? "'none'" : implode(' ', array_values(array_filter($sources, 'is_string'))),
            default => "'none'",
        };

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors {$frameAncestors}",
        ]);
    }

    /**
     * Config values cross a type boundary (`config()` returns mixed), so the list
     * is validated rather than assumed: non-arrays become empty, non-string
     * members are dropped. A malformed env value must degrade to a STRICTER
     * policy, never a broken header.
     *
     * @return list<string>
     */
    private function configuredOrigins(string $key): array
    {
        $value = config($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
