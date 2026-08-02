<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\OriginAllowlist;
use App\Domain\Auth\Widget\VisitorTokenStore;
use App\Domain\Auth\Widget\WidgetGuard;
use App\Domain\Auth\Widget\WidgetSessionRefused;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Models\Widget;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * POST /api/widget/v1/session — visitor session issuance (Doc 02 FR-21, FR-22,
 * AC-06, EC-08, EC-09).
 *
 * Supersedes Doc 01's widget ingress probe ON THE SAME MIDDLEWARE STACK, which
 * is exactly the hand-off the probe's comment promised. The widget key has
 * already resolved the tenant (`tenant:widget_key`); suspension does NOT block
 * this surface (`tenant.state:inbound` — a suspended operator's players keep
 * their chat identity; what stops is outbound sends, Doc 01 FR-05).
 *
 * THE SERVER LAYER of AC-06 lives here: origin and every declared ancestor
 * (EC-08) are validated against the widget's allowlist before any token
 * exists, with no dependence on the CORS middleware — sabotage one, the other
 * still refuses, and the suite proves both directions.
 *
 * HONESTY ABOUT THE ANCESTOR CHECK (owner ruling, step 10): the ancestor list
 * is CLIENT-SUPPLIED (the loader's location.ancestorOrigins), so against a
 * non-browser caller it is advisory. Its value is defense in depth for real
 * browsers; the structural half of EC-08 is the frame-ancestors CSP that
 * Doc 13 stamps when it serves the actual frame. The traceability row for
 * EC-08 stays PARTIAL until then.
 *
 * ISSUE vs REFRESH vs RE-ISSUE (FR-22, rulings 2/3):
 *
 *   no token presented              → fresh visitor            201
 *   token + matching cookie         → same visitor, same token,
 *                                     rotated cookie, slid TTL  200
 *   token without its cookie        → FRESH visitor, silently   201
 *                                     (metric logged; the old record and its
 *                                     TTL are untouched, and NO code path
 *                                     here can link the new visitor to it —
 *                                     re-linking is staff-only, EC-09/Doc 08)
 */
final class WidgetSessionController extends Controller
{
    public function __construct(
        private readonly OriginAllowlist $allowlist,
        private readonly VisitorTokenStore $store,
        private readonly TenantContext $context,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var array{origin?: string|null, ancestors?: list<string>|null} $input */
        $input = $request->validate([
            'origin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ancestors' => ['sometimes', 'array', 'max:16'],
            'ancestors.*' => ['string', 'max:255'],
        ]);

        $widget = $this->resolvedWidget($request);

        /** @var list<string> $entries */
        $entries = $widget->allowed_origins;

        $origin = $this->presentedOrigin($request, $input['origin'] ?? null);

        if (! $this->allowlist->matches($origin, $entries)) {
            throw WidgetSessionRefused::originNotAllowed($origin);
        }

        // EC-08: every frame above the widget must be allowlisted too. An
        // empty list is a top-level page and passes vacuously.
        /** @var list<string> $ancestors */
        $ancestors = $input['ancestors'] ?? [];

        foreach ($ancestors as $ancestor) {
            if (! $this->allowlist->matches($ancestor, $entries)) {
                throw WidgetSessionRefused::ancestorNotAllowed($ancestor);
            }
        }

        return $this->establish($request, $widget);
    }

    private function establish(Request $request, Widget $widget): JsonResponse
    {
        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $presentedToken = $request->header(WidgetGuard::TOKEN_HEADER);
        $presentedCookie = $request->cookies->get(WidgetGuard::COOKIE_NAME);

        if (is_string($presentedToken) && $presentedToken !== '') {
            $record = $this->store->find($tenantId, $presentedToken);

            $belongsHere = $record !== null && $record->widgetId === (string) $widget->getKey();

            if ($belongsHere && is_string($presentedCookie) && $record->cookieMatches($presentedCookie)) {
                // The rightful holder returning: FR-21's slide, FR-22's rotate.
                $cookie = $this->store->rotateCookie($tenantId, $presentedToken, $record, $now);

                return $this->sessionResponse($record->visitorId, $presentedToken, $cookie, status: 200);
            }

            // FR-22 theft containment: a token without its cookie (or from the
            // wrong widget) is NOT its visitor. Fall through to a fresh
            // identity — silent to the caller, visible to monitoring. The
            // presented token's record, if any, is deliberately not touched:
            // its rightful holder keeps their session and their TTL.
            Log::warning('widget visitor pairing mismatch — issuing fresh identity', [
                'reason' => $record === null ? 'unknown_token' : ($belongsHere ? 'cookie_mismatch' : 'widget_mismatch'),
                'security_metric' => 'widget.visitor_reissued',
            ]);
        }

        $issued = $this->store->issue($tenantId, (string) $widget->getKey(), $now);

        return $this->sessionResponse($issued->visitorId, $issued->token, $issued->cookie, status: 201);
    }

    private function sessionResponse(string $visitorId, string $token, string $cookie, int $status): JsonResponse
    {
        $response = new JsonResponse([
            'visitor_id' => $visitorId,
            'token' => $token,
            'expires_in' => VisitorTokenStore::TTL_SECONDS,
        ], $status);

        // BR-06's "separate cookie scope", literally: Path confines the FR-22
        // cookie to the widget surface, so it never accompanies a staff
        // request. SameSite=None because the widget lives on other people's
        // origins by definition; Secure is therefore mandatory, and SEC-06
        // already refuses this surface over cleartext. Set directly on the
        // response — this stateless group runs no cookie-queue middleware.
        $response->headers->setCookie(Cookie::create(
            name: WidgetGuard::COOKIE_NAME,
            value: $cookie,
            expire: time() + VisitorTokenStore::TTL_SECONDS,
            path: WidgetGuard::COOKIE_PATH,
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_NONE,
        ));

        return $response;
    }

    /**
     * The origin the SERVER layer validates: the browser-controlled Origin
     * header when present; the loader-declared body origin otherwise (a
     * non-browser caller sends no Origin header, and it must still be judged
     * — that independence from browser behavior is what makes this the
     * server layer). Both present and disagreeing is itself a refusal: a
     * page that says one thing to the browser and another to us gets neither.
     */
    private function presentedOrigin(Request $request, ?string $declared): string
    {
        $header = $request->headers->get('Origin');

        if (is_string($header) && $header !== '' && $header !== 'null') {
            if (is_string($declared) && $declared !== '' && strtolower(trim($declared)) !== strtolower(trim($header))) {
                throw WidgetSessionRefused::originNotAllowed($declared);
            }

            return $header;
        }

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        throw WidgetSessionRefused::originMissing();
    }

    private function resolvedWidget(Request $request): Widget
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        if (! is_string($keyId) || $keyId === '') {
            // Unreachable on the real stack (the binder always sets it for key
            // paths); a crash here means the route was mounted without its
            // binder, which must never be survivable.
            throw new \LogicException('Widget session route reached without a resolved widget key (Doc 02 FR-21).');
        }

        $widget = Widget::query()->where('key_id', $keyId)->first();

        if ($widget === null) {
            // A minted key with no widget record: fail closed, same shape as
            // any other refusal (no oracle for "key exists but unconfigured").
            throw WidgetSessionRefused::widgetUnconfigured();
        }

        return $widget;
    }
}
