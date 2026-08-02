<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Tokens\TokenRefused;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant.bearer` — tenant resolution for the stateless token surface
 * (Doc 02 FR-18/FR-19/FR-20, T-02; Doc 01 FR-03a).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A FOURTH BINDER RATHER THAN A BRANCH IN ResolveTenant.
 *
 * ResolveTenant's whole safety argument is "the source comes from the route,
 * not the request" — each route group names the ONE credential it trusts.
 * Bearer tokens resolve tenant the FR-03a way (token → user → tenant, the
 * `staff_session` source; the ResolutionSource enum has said so since Doc 01),
 * but through a different credential than the session surface. Overloading
 * `resolveStaff()` to try sessions-then-tokens would mean every staff-session
 * route silently accepted a second credential kind — exactly the confusion
 * ResolveTenant exists to rule out. So the bearer surface declares its own
 * binder; the enum's closed set is untouched.
 *
 * THE DOOR. The token table is FORCE-RLS'd and the request presents nothing
 * but the token, so the row must be found before any tenant can be bound —
 * `resolve_api_token()`, SECURITY DEFINER, owned by NOLOGIN key_resolver, is
 * the only path (see the personal_access_tokens migration). It answers with
 * the flags the FR-20 refusals need in the same round trip; after binding,
 * everything downstream (the Sanctum guard included) runs under ordinary RLS.
 *
 * REFUSAL ORDER IS DELIBERATE (step-9 ruling): unknown, revoked and
 * disabled-owner all throw the SAME invalid_token before expiry is even
 * considered, so a revoked-and-expired token cannot leak "this credential was
 * once real" through the more specific code. Expired is the one distinction
 * FR-20 grants, and only for a token that would otherwise still be alive.
 *
 * WHAT A REFUSAL LEAVES BEHIND: nothing bound, nothing readable — plus a log
 * line with the C-10-shaped security metric, because a stream of invalid
 * bearer presentations is a probe someone should see.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ResolveBearerTenant
{
    /**
     * Where the resolved token row's id is stashed for the request. Consumed
     * by RequirePermission's token branch (T-05) — the guard re-loads the
     * model under RLS, but the ATTRIBUTE is what marks this request as
     * token-authenticated in the first place.
     */
    public const TOKEN_ID_ATTRIBUTE = 'bearer_token_id';

    public function __construct(
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            throw TokenRefused::invalid('missing');
        }

        $row = $this->lookup(hash('sha256', $bearer));

        if ($row === null) {
            $this->recordRefusal('unknown');

            throw TokenRefused::invalid('unknown');
        }

        if ($row->revoked) {
            $this->recordRefusal('revoked', $row->token_id);

            throw TokenRefused::invalid('revoked');
        }

        // BR-04's bearer-door backstop: a disabled owner's token dies with the
        // account, even if the atomic revocation somehow missed this row.
        if ($row->user_disabled) {
            $this->recordRefusal('owner_disabled', $row->token_id);

            throw TokenRefused::invalid('owner_disabled');
        }

        if ($row->expired) {
            $this->recordRefusal('expired', $row->token_id);

            throw TokenRefused::expired();
        }

        $correlationId = $this->inboundCorrelationId($request);

        $this->context->bind($row->tenant_id, ResolutionSource::StaffSession, $correlationId);
        $this->rls->set($row->tenant_id);

        $request->attributes->set(self::TOKEN_ID_ATTRIBUTE, $row->token_id);

        try {
            return $next($request);
        } finally {
            // Octane: this connection outlives the request — same shape and same
            // reason as ResolveTenant's finally.
            $this->rls->clear();
            $this->context->clear();
        }
    }

    /**
     * @return object{token_id: string, tenant_id: string, tokenable_type: string,
     *     tokenable_id: string|null, abilities: string|null, expired: bool,
     *     revoked: bool, user_disabled: bool, tenant_state: string}|null
     */
    private function lookup(string $hash): ?object
    {
        /** @var list<object{token_id: string, tenant_id: string, tokenable_type: string, tokenable_id: string|null, abilities: string|null, expired: bool, revoked: bool, user_disabled: bool, tenant_state: string}> $rows */
        $rows = DB::select('SELECT * FROM resolve_api_token(?)', [$hash]);

        return $rows[0] ?? null;
    }

    /**
     * SEC-06's metric shape, applied to the bearer surface. The token id is
     * safe to log (it is not the credential — the hash is, and neither the
     * hash nor the plaintext is ever written anywhere, SEC-08/C-13).
     */
    private function recordRefusal(string $reason, ?string $tokenId = null): void
    {
        Log::warning('bearer token refused', [
            'reason' => $reason,
            'token_id' => $tokenId,
            'security_metric' => 'auth.token.refused',
        ]);
    }

    private function inboundCorrelationId(Request $request): ?string
    {
        $candidate = $request->header('X-Correlation-Id');

        if (! is_string($candidate)) {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) === 1
            ? $candidate
            : null;
    }
}
