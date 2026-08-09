<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc 05 FR-01/T-02: the bot key rides the WEBHOOK URL PATH, because Telegram
 * sends exactly two credentials — the URL it was registered with and the
 * `secret_token` header — and cannot attach X-Tenant-Key.
 *
 * This shim copies the {bot_key} route segment into the header ResolveTenant
 * already reads, so the S15 machinery (binder → throttle → secret → state)
 * runs UNTOUCHED on the real route. It runs FIRST in the stack; a
 * client-supplied X-Tenant-Key is overwritten by the path value whenever the
 * segment is present (one credential, one source of truth). Header-borne
 * calls with no path segment (the Doc 02 proof suites) pass through
 * unchanged.
 */
final class TelegramWebhookKeyFromPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $botKey = $request->route('bot_key');

        if (is_string($botKey) && $botKey !== '') {
            $request->headers->set('X-Tenant-Key', $botKey);
        }

        $response = $next($request);
        if (! $response instanceof Response) {
            throw new \UnexpectedValueException('middleware pipeline returned a non-Response');
        }

        return $response;
    }
}
