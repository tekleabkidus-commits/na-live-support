<?php

declare(strict_types=1);

namespace App\Http\Controllers\Foundation;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports what resolution decided, and nothing else (Doc 01 FR-03, FR-04; AC-02).
 *
 * The instrument AC-02 needs. "500 requests with forged tenant_id in body/query/header
 * → zero effect on resolution (PROVEN BY TRACE IDS)" — proving a negative requires an
 * endpoint that states positively which tenant it resolved and by which path, so a
 * forged request can be shown to have resolved to the same tenant as a clean one.
 *
 * It deliberately ECHOES BACK the forged input alongside the resolved tenant. That
 * makes the test's evidence self-contained: one response shows both what was attempted
 * and what the platform actually did, so a reader does not have to trust the test's
 * description of the attack.
 *
 * Doc 05 replaces the telegram route's handler, Doc 13 the widget's, Doc 14 the DLR's.
 * The middleware stack they inherit is the deliverable; this controller is not.
 */
final class IngressProbeController
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        return new JsonResponse($this->payload($request, $context));
    }

    /**
     * Inbound surfaces (webhook, widget, DLR) acknowledge with 202.
     *
     * 202 rather than 200 because that is the honest status for these surfaces once
     * they are real: FR-05 requires a SUSPENDED tenant's inbound traffic to be
     * "accepted and queued", so the channel is being told "received, not yet
     * processed". A 200 would imply completion that has not happened, and a channel
     * that retries on non-2xx would then be told to stop retrying by a lie.
     */
    public function ingest(Request $request, TenantContext $context): JsonResponse
    {
        return new JsonResponse($this->payload($request, $context), 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, TenantContext $context): array
    {
        return [
            // What the platform actually decided.
            'resolved_tenant_id' => $context->tenantId(),
            'resolution_source' => $context->source()?->value,
            'trace_id' => $context->correlationId(),

            // What the caller TRIED to assert, echoed so AC-02's evidence is
            // self-contained. Read straight off the request and never used for
            // anything — the resolver has no code path from request input to a tenant
            // id, which is why FR-04 needs no validation rule.
            'ignored_client_assertions' => [
                'body_tenant_id' => $request->input('tenant_id'),
                'query_tenant_id' => $request->query('tenant_id'),
                'header_tenant_id' => $request->header('X-Tenant-Id'),
            ],
        ];
    }
}
