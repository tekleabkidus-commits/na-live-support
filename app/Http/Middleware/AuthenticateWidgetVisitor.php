<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `widget.auth` — authentication for the visitor surface (Doc 02 FR-21,
 * T-02, BR-06).
 *
 * DELIBERATELY NOT Laravel's Authenticate middleware, and the reason is the
 * middleware SORTER. Authenticate implements AuthenticatesRequests, which is
 * priority-listed, so the sorter hoists it over unlisted predecessors — the
 * step-9 bug. Step 10's first fix added ResolveTenant to the priority list to
 * hold the widget order, which quietly RE-SORTED THE STAFF STACK TOO:
 * ResolveTenant was hoisted above the group-appended SecurityHeaders there,
 * and a staff 404 rendered inside the hoisted binder never passed through the
 * header stamp — SecurityHeadersTest caught T-07 vanishing from error
 * responses. A global ordering rule was the wrong tool for a one-surface
 * problem.
 *
 * This class is the right tool: it does the ONE thing auth:widget needed —
 * refuse the request unless the widget guard authenticates — while being
 * invisible to the priority list, so every stack keeps its declared order and
 * no other surface moves. What is deliberately NOT done here:
 *
 *   no shouldUse()   Authenticate would make `widget` the request's DEFAULT
 *                    guard, which is how a WidgetVisitor ended up inside the
 *                    staff channel callback's User type declaration (a 500
 *                    where a 403 was owed). The default guard stays what it
 *                    was; everything on this surface names the widget guard
 *                    explicitly, and the channel callbacks are guards-pinned.
 */
final class AuthenticateWidgetVisitor
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->guard('widget')->check()) {
            throw new AuthenticationException('Unauthenticated.', ['widget']);
        }

        return $next($request);
    }
}
