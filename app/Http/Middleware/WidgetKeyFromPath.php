<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc 13 T-01 / FR-01: the per-key shim `GET /w/{widget_key}.js` and the panel
 * document `GET /w/panel/{widget_key}` carry the widget key in the PATH (a
 * <script src> and an <iframe src> cannot send headers). This copies the route
 * parameter into the `X-Tenant-Key` header so the ONE resolver
 * (`tenant:widget_key` → TenantResolver::resolveKey) does the lookup — never a
 * second resolver, never a second key parser (the prohibition list).
 */
final class WidgetKeyFromPath
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('widget_key');
        if (is_string($key) && $key !== '' && ! $request->headers->has('X-Tenant-Key')) {
            $request->headers->set('X-Tenant-Key', $key);
        }

        return $next($request);
    }
}
