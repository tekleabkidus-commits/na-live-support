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
 * BOTH LAYERS ENFORCE, FOR REAL (the Doc 02-era staff pass-through closed at
 * S19 step 7 — this is the flip its old docblock declared):
 *
 *   - STAFF principals: the declared key is resolved through the Doc 03
 *     capability map (role + overrides + expiries, ≤10 s cache, R-04 bypass
 *     keys always fresh). A deny is T-02's 403 problem-details
 *     `{code:"forbidden", permission:key}` — named for staff clients — and is
 *     logged + counted per tenant (FR-11). Object-existence masking (SEC-02's
 *     404 for out-of-scope reads) belongs to the scope layer on object
 *     routes; this middleware answers the VERB.
 *
 *   - TOKEN principals: the T-05 intersection (token.abilities ∩
 *     owner.effective_permissions — now GENUINELY resolved, service accounts
 *     included per BR-07). A miss is FR-20's 403 insufficient_scope NAMING
 *     THIS KEY (AC-05), audited as auth.token.scope_denied and published.
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
        private readonly \App\Support\Rbac\CapabilityMapProvider $maps,
        private readonly \App\Support\Rbac\DeniedCheckRecorder $denials,
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

            return $next($request);
        }

        $verdict = $this->enforceStaffPermission($request, $permissionKey);
        if ($verdict !== null) {
            return $verdict;
        }

        return $next($request);
    }

    /**
     * THE STAFF ENFORCEMENT FLIP (S19 step 7 — the docblock's declared seam,
     * closed): every `permission:` declaration is now a real verdict against
     * the Doc 03 resolver. Null means "allowed, continue".
     */
    private function enforceStaffPermission(Request $request, string $permissionKey): ?Response
    {
        $user = $request->user();

        // A permission-declared route with no authenticated STAFF principal is
        // a wiring mistake (the auth middleware runs first by construction) —
        // refuse rather than pass, same posture as the token branch.
        if (! $user instanceof User) {
            abort(401);
        }

        $verdict = $this->maps->verdictFor($user, $permissionKey);
        if ($verdict->allowed) {
            return null;
        }

        // FR-11: every API check failure logged + counted per tenant.
        $this->denials->record(
            permission: $permissionKey,
            source: $verdict->source,
            actorId: $user->identifier(),
        );

        // T-02/SEC-02: 403 problem-details NAMING the key for staff clients.
        // Object-existence masking (out-of-scope reads → 404) is the SCOPE
        // layer's job on object routes — this branch answers "may this VERB
        // happen at all".
        return ProblemDetails::response(
            code: ErrorCode::Forbidden,
            status: 403,
            detail: (string) __('rbac.denied', ['permission' => $permissionKey]),
            extra: ['permission' => $permissionKey],
        );
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
