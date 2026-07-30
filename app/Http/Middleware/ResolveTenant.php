<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantNotResolved;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binding 1 of 4 — HTTP (Doc 01 T-05, FR-03).
 *
 * Declared per route group with the source it trusts:
 *
 *   ->middleware('tenant:staff_session')     staff web/API
 *   ->middleware('tenant:api_key')           public API
 *   ->middleware('tenant:telegram_bot_key')  Telegram webhook
 *   ->middleware('tenant:widget_key')        widget
 *   ->middleware('tenant:sms_gateway_key')   SMS DLR webhook
 *
 * THE SOURCE COMES FROM THE ROUTE, NOT THE REQUEST. A widget route can only ever
 * resolve a widget key; presenting an API key there resolves nothing. That removes
 * the whole class of confusion attacks where a credential valid for one surface is
 * replayed against another, and it means no code has to compare a channel name to a
 * literal (invariant N-20).
 *
 * WHERE THE CREDENTIAL IS READ FROM. For key paths, the `Authorization: Bearer` or
 * `X-Tenant-Key` header — the credential itself, which is client-supplied by
 * definition and is the one thing that legitimately is. What is NEVER read is a
 * tenant id: the credential is a secret to be verified, not an assertion to be
 * trusted. FR-04 holds because the resolver has no path from request input to a
 * tenant id at all.
 *
 * The RLS session variable is set here, immediately after binding, and cleared in a
 * `finally` — both layers move together or the request fails.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $source = 'staff_session'): Response
    {
        $resolutionSource = ResolutionSource::tryFrom($source)
            ?? throw new \LogicException(sprintf(
                'Route declares tenant source "%s", which is not one of the six FR-03 paths. The set is '
                .'closed by specification; adding one is an ADR, not a route change.',
                $source,
            ));

        $resolved = $resolutionSource === ResolutionSource::StaffSession
            ? $this->resolver->resolveStaff($request)
            : $this->resolver->resolveKey($resolutionSource, $this->presentedCredential($request, $resolutionSource));

        // C-14: honour an inbound correlation id so a trace spans the caller and us,
        // but only if it is a well-formed uuid — an unvalidated value would let a
        // caller poison the log stream of every job this request spawns.
        $correlationId = $this->inboundCorrelationId($request);

        $this->context->bind($resolved->tenantId, $resolved->source, $correlationId);
        $this->rls->set($resolved->tenantId);

        try {
            return $next($request);
        } finally {
            // R-01/EC-01. Under Octane this connection outlives the request, so the
            // clear is not tidiness — it is the control that stops the next request
            // on this connection inheriting this tenant's visibility. In a `finally`
            // so an exception cannot skip it.
            $this->rls->clear();
            $this->context->clear();
        }
    }

    private function presentedCredential(Request $request, ResolutionSource $source): string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Tenant-Key');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        throw TenantNotResolved::forSource($source);
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
