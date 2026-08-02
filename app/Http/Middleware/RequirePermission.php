<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\Tokens\TokenScopeGate;
use App\Support\Events\EventPublisher;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<key>` — the route→permission mapping the permission-mapping gate
 * reads, and the anchor both enforcement layers attach to (Doc 02 T-02/T-05,
 * Doc 03 AC-01).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES AND DOES NOT ENFORCE TODAY — STATED SO NOBODY MISREADS IT.
 *
 * This middleware DECLARES which permission a route requires, verifies the key
 * is registered (a typo'd declaration throws in local/testing, exactly like
 * DeclareAuditAction), and records the requirement on the request.
 *
 *   - STAFF SESSION enforcement (does this user's role grant the key?) is
 *     Doc 03's resolver. Until Doc 03 lands, an authenticated staff user passes:
 *     there are no roles to check a grant against, and inventing interim role
 *     logic here was ruled scope creep in the Doc 02 plan. The declaration is
 *     still load-bearing NOW: the gate proves every route names its key, so
 *     Doc 03 flips enforcement on without a route audit.
 *
 *   - TOKEN principals are enforced HERE, for real, since the token step: a
 *     request that arrived through ResolveBearerTenant carries a token id, and
 *     the declared key is checked against the T-05 intersection
 *     (token.abilities ∩ owner.effective_permissions, TokenScopeGate's 10 s
 *     cache). A miss is FR-20's 403 insufficient_scope NAMING THIS KEY
 *     (AC-05), audited as auth.token.scope_denied and published on the bus.
 *
 * If you are reading this while building Doc 03: replace the staff pass-through
 * with the resolver verdict; do not add a second permission middleware, and do
 * not touch the token branch — it already consumes your resolver through the
 * EffectivePermissions seam.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RequirePermission
{
    public const REQUEST_ATTRIBUTE = 'required_permission';

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TokenScopeGate $gate,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

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

        if ($request->attributes->has(ResolveBearerTenant::TOKEN_ID_ATTRIBUTE)) {
            $verdict = $this->enforceTokenScope($request, $permissionKey);

            if ($verdict !== null) {
                return $verdict;
            }
        }

        return $next($request);
    }

    /**
     * The T-05 enforcement point. Null means "allowed, continue".
     */
    private function enforceTokenScope(Request $request, string $permissionKey): ?Response
    {
        $user = $request->user('api');
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        // Behind auth:api both are always present; reaching here without them
        // means the middleware order changed. Refuse rather than pass — an
        // enforcement point that fails open on a wiring mistake is not one.
        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            abort(401);
        }

        if ($this->gate->allows($token, $user, $permissionKey)) {
            return null;
        }

        $this->recordDenial($token, $user, $permissionKey);

        return ProblemDetails::response(
            code: ErrorCode::InsufficientScope,
            status: 403,
            // FR-20/AC-05: the refusal NAMES the missing permission. This is
            // an authenticated principal being told what its own token lacks —
            // nothing is enumerated that the caller could not learn from the
            // catalog (contrast the door's deliberately mute invalid_token).
            detail: (string) __('auth.token.insufficient_scope', ['permission' => $permissionKey]),
            extra: ['missing_permission' => $permissionKey],
        );
    }

    private function recordDenial(PersonalAccessToken $token, User $user, string $permissionKey): void
    {
        // One transaction: the audit row and the outbox row commit together or
        // not at all (C-06 — the publisher refuses to run outside one).
        DB::transaction(function () use ($token, $user, $permissionKey): void {
            $this->audit->record(
                actionKey: 'auth.token.scope_denied',
                entity: 'personal_access_token',
                entityId: $token->identifier(),
                // BR-03: a machine token's denial is attributed to its visible
                // service account, exactly like any staff principal — never to
                // "the system".
                actorType: AuditActor::Staff,
                actorId: $user->identifier(),
                payload: ['permission' => $permissionKey, 'user_id' => $user->identifier()],
            );

            $this->events->publish('auth.token.scope_denied', [
                'tenant_id' => $user->tenantId(),
                'user_id' => $user->identifier(),
                'token_id' => $token->identifier(),
                'permission' => $permissionKey,
            ]);
        });
    }
}
