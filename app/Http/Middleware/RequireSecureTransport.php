<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-06: "Auth refuses to operate over plain HTTP in any environment."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THERE IS NO ENVIRONMENT CARVE-OUT, AND WHY THAT IS THE WHOLE POINT.
 *
 * The conventional implementation of this rule is `if (app()->isProduction())`,
 * and it is what SEC-06 exists to forbid. The reason is not purity:
 *
 *   - A developer's session cookie, TOTP code and password cross the same wire
 *     as production's. "It's only local" assumes the local machine is not on a
 *     shared network, which is an assumption, not a control.
 *   - More importantly: a rule enforced only in production is UNTESTED. CI runs
 *     in `testing`, so the carve-out means every test exercises the unenforced
 *     path, and the enforced path ships having never once executed. The first
 *     time it runs is in production, on real traffic.
 *
 * So this refuses everywhere and the test suite asserts the refusal in
 * `testing` — which is why tests/Security/HttpsRefusalTest.php makes requests to
 * http:// URLs and expects 426 rather than mocking anything.
 *
 * 426 Upgrade Required (not 403, not a redirect): a redirect invites the client
 * to replay a request whose secrets already crossed cleartext, and 403 says
 * "you may not" when the truth is "not over this transport". 426 says exactly
 * what is wrong and names the transport in the `Upgrade` header.
 *
 * WHAT THIS DOES NOT DO. It does not decide whether X-Forwarded-Proto can be
 * believed — that is trusted-proxy configuration, and Doc 18 FR-12 owns it along
 * with the load balancer that sets the header. Until that lands the header is
 * untrusted, which is the safe direction: an attacker who could set it would
 * otherwise turn this middleware off with one header.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RequireSecureTransport
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure()) {
            return $next($request);
        }

        // The one exemption, and it is not a transport exemption: console-driven
        // and health-probe traffic never carries a credential. `/up` is polled by
        // the container healthcheck over loopback INSIDE the container, before
        // any proxy exists to terminate TLS.
        if ($request->is('up')) {
            return $next($request);
        }

        $problem = ProblemDetails::response(
            code: ErrorCode::InsecureTransport,
            status: 426,
            detail: __('foundation.error.insecure_transport_detail'),
        );

        // RFC 9110 §15.5.22: 426 MUST name the required protocol.
        $problem->headers->set('Upgrade', 'TLS/1.2, HTTP/1.1');
        $problem->headers->set('Connection', 'Upgrade');

        return $problem;
    }
}
