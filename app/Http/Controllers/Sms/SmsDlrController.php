<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Http\Middleware\ResolveTenant;
use App\Jobs\Sms\ProcessDlrJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-13 / NFR-03 / EC-03 — the DLR ingress (P-DLR-AUTH's handler).
 * ACK-only by design: the route mutates nothing; the raw payload is handed
 * to ProcessDlrJob on the `sms-dlr` lane (Horizon amendment 1) where the
 * driver's parseDlr, the precedence-safe state update, the raw-code
 * retention and the events happen, each with its own audit entry. The 202
 * carries the Doc 01 resolution facts (ids only) so the auth proofs read the
 * same shape the probe answered. A `success`-only callback (GeezSMS) reaches
 * `accepted` and nothing beyond (R-D14-1.5). The payload is never logged
 * here (it carries the message text and the phone — SEC-02).
 */
final class SmsDlrController
{
    public function ingest(Request $request, TenantContext $context): JsonResponse
    {
        // The authenticated key names the gateway: the binder stashed the tenant-key
        // id, the webhook credential rides it, and the gateway row points at the
        // credential — no payload field is trusted for identity (SEC-01).
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);
        $gatewayId = is_string($keyId)
            ? DB::table('sms_gateways')
                ->join('webhook_credentials', 'webhook_credentials.id', '=', 'sms_gateways.webhook_credential_id')
                ->where('webhook_credentials.key_id', $keyId)
                ->whereNull('sms_gateways.deleted_at')
                ->value('sms_gateways.id')
            : null;

        if (is_string($gatewayId)) {
            $decoded = $request->json()->all();
            $decoded = $decoded === [] ? $request->post() : $decoded;
            $payload = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $payload[$key] = $value;
                }
            }
            ProcessDlrJob::dispatch($gatewayId, $payload);
        }

        return new JsonResponse([
            'accepted' => true,
            'resolved_tenant_id' => $context->tenantId(),
            'resolution_source' => $context->source()?->value,
            'trace_id' => $context->correlationId(),
        ], 202);
    }
}
