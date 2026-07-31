<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Rbac\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<key>` — the route→permission mapping the permission-mapping gate
 * reads, and the anchor both enforcement layers attach to (Doc 02 T-02/T-05,
 * Doc 03 AC-01).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES AND DOES NOT ENFORCE TODAY — STATED SO NOBODY MISREADS IT.
 *
 * Today this middleware DECLARES which permission a route requires, verifies the
 * key is registered (a typo'd declaration throws in local/testing, exactly like
 * DeclareAuditAction), and records the requirement on the request.
 *
 *   - STAFF SESSION enforcement (does this user's role grant the key?) is
 *     Doc 03's resolver. Until Doc 03 lands, an authenticated staff user passes:
 *     there are no roles to check a grant against, and inventing interim role
 *     logic here was ruled scope creep in the Doc 02 plan. The declaration is
 *     still load-bearing NOW: the gate proves every route names its key, so
 *     Doc 03 flips enforcement on without a route audit.
 *
 *   - TOKEN principals get real enforcement in Doc 02's token step: the declared
 *     key is what the token-scope intersection (T-05) checks against, producing
 *     403 insufficient_scope naming this key (FR-20/AC-05).
 *
 * If you are reading this while building Doc 03: replace the staff pass-through
 * with the resolver verdict; do not add a second permission middleware.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RequirePermission
{
    public const REQUEST_ATTRIBUTE = 'required_permission';

    public function __construct(private readonly PermissionRegistry $registry) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        if (app()->environment(['local', 'testing'])) {
            $this->registry->assertRegistered($permissionKey);
        } elseif (! $this->registry->isRegistered($permissionKey)) {
            logger()->error('Route declares unregistered permission key', [
                'permission_key' => $permissionKey,
                'route' => $request->route()?->getName() ?? $request->path(),
            ]);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $permissionKey);

        return $next($request);
    }
}
