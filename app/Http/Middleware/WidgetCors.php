<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Widget\OriginAllowlist;
use App\Domain\Auth\Widget\WidgetGuard;
use App\Models\Widget;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-widget CORS (Doc 02 T-06, AC-06).
 *
 * T-06: "widget endpoints CORS-allowlisted per widget config." This is the
 * CORS half of AC-06's two independent layers:
 *
 *   CORS layer    Access-Control-Allow-Origin is stamped ONLY when the
 *                 request's Origin is on the resolved widget's allowlist. A
 *                 disallowed page's fetch() executes, but the browser refuses
 *                 to hand the response to it — protection that stands even if
 *                 the server-layer check were deleted (AC-06's independence
 *                 clause, proven by exactly that sabotage in the suite).
 *   server layer  WidgetSessionController refuses issuance outright — the
 *                 layer a non-browser caller meets, since curl obeys no CORS.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY PREFLIGHT IS ANSWERED PERMISSIVELY (step-10 ruling, decision 5 as
 * planned). A preflight OPTIONS carries no custom headers and no body — by
 * design, that is what makes it "simple" — so the widget key is not available
 * and the allowlist CANNOT be known yet. Answering with a reflected origin
 * grants nothing: preflight approval only permits the browser to SEND the
 * real request, and the real request meets both enforcement layers above.
 * The alternative — a key-aware preflight — would need a pre-tenant lookup
 * door keyed on non-secret data, widening the SECURITY DEFINER surface for a
 * courtesy response. Refusal still happens where refusal is enforceable.
 *
 * Allow-Credentials is stamped because the FR-22 cookie must ride on widget
 * requests. Reflect-plus-credentials is exactly the pattern CORS folklore
 * warns about, so, plainly: it is safe HERE because no response on this
 * surface is readable without ALSO holding the widget's own allowlisted
 * origin (this middleware's response gate) — the reflected preflight guards
 * nothing and reveals nothing.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * PLACEMENT IS LOAD-BEARING, twice over. (1) In the group it sits INSIDE
 * `tenant:widget_key` and OUTSIDE `auth:widget`: inside the binder because the
 * response-side allowlist read needs the bound RLS scope, which ResolveTenant
 * clears in its `finally` — wrapped the other way, the stamp would query a
 * cleared scope and 500; outside the guard because the guard's own 401 must
 * reach the allowed page CORS-readable, or the decision-3 recovery (401 →
 * re-POST /session) becomes an opaque network error. The priority-list entry
 * in bootstrap/app.php is what makes this executed order real — see step 9's
 * sorter lesson. (2) Preflights, which present no key and so can never pass
 * the binder, are served by a dedicated OPTIONS route carrying ONLY this
 * middleware; the preflight branch below belongs to that route.
 */
final class WidgetCors
{
    private const ALLOWED_METHODS = 'GET, POST, DELETE, OPTIONS';

    private const ALLOWED_HEADERS = 'Content-Type, X-Tenant-Key, X-Correlation-Id, '.WidgetGuard::TOKEN_HEADER;

    private const MAX_AGE_SECONDS = '600';

    public function __construct(private readonly OriginAllowlist $allowlist) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')) {
            return $this->preflight($origin);
        }

        $response = $next($request);

        // Every response varies by Origin whether or not headers were stamped,
        // so no shared cache ever serves one origin's CORS verdict to another.
        $response->headers->set('Vary', 'Origin', false);

        if ($origin !== null && $this->originAllowedForResolvedWidget($request, $origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function preflight(?string $origin): Response
    {
        $response = new Response('', 204);

        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Max-Age', self::MAX_AGE_SECONDS);
        $response->headers->set('Vary', 'Origin');

        return $response;
    }

    /**
     * The response-side gate. Runs INSIDE the tenant middleware's scope (this
     * middleware wraps it), so when a widget key resolved the request, the
     * key id attribute is set and the widgets table is readable under RLS.
     * A request that never resolved (404s, refusals before binding) stamps
     * nothing — an unresolvable caller learns nothing about any allowlist.
     */
    private function originAllowedForResolvedWidget(Request $request, string $origin): bool
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        if (! is_string($keyId) || $keyId === '') {
            return false;
        }

        $widget = Widget::query()->where('key_id', $keyId)->first();

        if ($widget === null) {
            return false;
        }

        /** @var list<string> $entries */
        $entries = $widget->allowed_origins;

        return $this->allowlist->matches($origin, $entries);
    }
}
