<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc 14 FR-13 / SEC-01 (P-DLR-AUTH): the gateway key rides the DLR callback
 * URL PATH, because an unsigned aggregator sends exactly one credential — the
 * URL it was given per send (`callback`, vendor notes §9) — and cannot attach
 * X-Tenant-Key. MIRRORED from Doc 05's TelegramWebhookKeyFromPath (the
 * consume-or-mirror ruling: that class is Doc 05's by name and docblock).
 *
 * Copies the {gateway_key} route segment into the header ResolveTenant
 * already reads, so the Doc 02 machinery (binder → throttle → secret → state)
 * runs UNTOUCHED. Runs FIRST in the stack; a client-supplied X-Tenant-Key is
 * overwritten by the path value whenever the segment is present (one
 * credential, one source of truth). Header-borne calls with no segment (Doc
 * 02's WebhookHmacTest) pass through unchanged.
 */
final class SmsDlrKeyFromPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $gatewayKey = $request->route('gateway_key');

        if (is_string($gatewayKey) && $gatewayKey !== '') {
            $request->headers->set('X-Tenant-Key', $gatewayKey);
        }

        $response = $next($request);
        if (! $response instanceof Response) {
            throw new \UnexpectedValueException('middleware pipeline returned a non-Response');
        }

        return $response;
    }
}
