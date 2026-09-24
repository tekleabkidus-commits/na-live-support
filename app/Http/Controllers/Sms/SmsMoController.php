<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\OptOut\OptOutRegistry;
use App\Domain\Sms\OptOut\StopParser;
use App\Domain\Sms\Support\PhoneMask;
use App\Http\Middleware\ResolveTenant;
use App\Models\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-15's MO door (N-15 IS THE LAW HERE): SMS is notification-only — this
 * door PARSES and nothing else. No conversation is minted, no message row,
 * no customer identity, no threading; a STOP in a configured language
 * becomes an opt-out row and a confirmation where the market knob says so;
 * EVERYTHING else is counted and dropped (`metric: sms.mo.non_stop_dropped`).
 * Same auth stack as the DLR door (the gateway key + URL secret resolve the
 * tenant); capability-gated — a gateway whose mapping does not declare
 * `inbound_stop` cannot opt anyone out through this door (counted drop).
 * The inbound text itself is never logged (it is a customer's words).
 */
final class SmsMoController
{
    public function __construct(
        private readonly StopParser $parser,
        private readonly OptOutRegistry $registry,
    ) {}

    public function ingest(Request $request): JsonResponse
    {
        $gateway = $this->gateway($request);
        if ($gateway === null) {
            $request->attributes->set('audit.noop', true); // A-37: a keyless delivery mutates nothing

            return new JsonResponse(['accepted' => true, 'opted_out' => false], 202);
        }

        $config = \App\Domain\Sms\Gateways\GatewayConfig::fromStored($gateway->config);
        $from = $request->input($config->dlrString('mo_from_key') ?? 'from');
        $inbound = $request->input($config->dlrString('mo_text_key') ?? 'text');

        if (! $config->capabilities()->inboundStop || ! is_string($from) || $from === '' || ! is_string($inbound)) {
            // a gateway that cannot receive MO, or a shapeless delivery: counted, dropped, NOTHING minted
            Log::info('sms mo dropped', ['gateway_id' => $gateway->identifier(), 'reason' => $config->capabilities()->inboundStop ? 'shapeless' : 'no_inbound_stop_capability', 'metric' => 'sms.mo.non_stop_dropped']);
            $request->attributes->set('audit.noop', true);

            return new JsonResponse(['accepted' => true, 'opted_out' => false], 202);
        }

        $phoneE164 = str_starts_with($from, '+') ? $from : '+'.$from;
        $matched = $this->parser->match($inbound);

        if ($matched === null || preg_match('/^\+[1-9]\d{6,14}$/', $phoneE164) !== 1) {
            Log::info('sms mo dropped', ['gateway_id' => $gateway->identifier(), 'reason' => 'non_stop', 'metric' => 'sms.mo.non_stop_dropped', 'from_masked' => PhoneMask::forLog($phoneE164)]);
            $request->attributes->set('audit.noop', true);

            return new JsonResponse(['accepted' => true, 'opted_out' => false], 202);
        }

        $optoutId = $this->registry->addFromInbound($phoneE164, $matched['word']);
        if ($optoutId === null) {
            $request->attributes->set('audit.noop', true); // already opted out — absoluteness needs no second row
        }

        return new JsonResponse(['accepted' => true, 'opted_out' => true], 202);
    }

    private function gateway(Request $request): ?SmsGateway
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);
        if (! is_string($keyId)) {
            return null;
        }
        $gatewayId = DB::table('sms_gateways')
            ->join('webhook_credentials', 'webhook_credentials.id', '=', 'sms_gateways.webhook_credential_id')
            ->where('webhook_credentials.key_id', $keyId)
            ->whereNull('sms_gateways.deleted_at')
            ->value('sms_gateways.id');

        return is_string($gatewayId) ? SmsGateway::query()->find($gatewayId) : null;
    }
}
