<?php

declare(strict_types=1);

namespace App\Domain\Sms\Dlr;

use App\Domain\Sms\Gateways\Drivers\DriverRegistry;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Pipeline\SmsLifecycle;
use App\Domain\Sms\State\SmsDeliveryState;
use App\Domain\Sms\State\SmsDeliveryStateMachine;
use App\Domain\Sms\Support\PhoneMask;
use App\Models\SmsGateway;
use App\Models\SmsMessage;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-13's application half (behind the P-DLR-AUTH door, on the sms-dlr lane):
 * the driver's mapping normalizes the raw payload, the state machine applies
 * it PRECEDENCE-SAFE and idempotent, the raw code is RETAINED on `raw_dlrs`
 * whatever happens (EC-04: a contradiction is kept AND flagged, never
 * overwritten), and the audit trail names what the receipt did:
 * `sms.dlr.applied` / `sms.dlr.contradiction` / `sms.dlr.unknown_provider_id`.
 *
 * Matching: `provider_id` first (the partial unique (tenant, gateway,
 * provider_id) is the lookup key, EC-03); the GeezSMS profile's documented
 * fallback (§4: phone + message text, digits phone) second — in memory only,
 * never logged. A receipt that matches nothing is LOGGED, DROPPED, COUNTED
 * (`metric: sms.dlr.unknown_provider_id`) — never a crash, never a guess.
 *
 * Cost correction: neither fixture mapping re-rates on the DLR (`ParsedDlr`
 * carries no money) — the FS-04 forward-only correction lands with step 11's
 * meter; `cost_corrected_amount` stays untouched here, stated plainly.
 */
final class DlrApplier
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly SmsDeliveryStateMachine $machine,
        private readonly SmsLifecycle $lifecycle,
        private readonly AuditLogger $audit,
        private readonly DueSet $timers,
    ) {}

    /** @param array<string, mixed> $payload */
    public function apply(SmsGateway $gateway, array $payload): string
    {
        $config = GatewayConfig::fromStored($gateway->config);
        $parsed = $this->drivers->for($gateway)->parseDlr($payload, $config);

        $row = $this->match($gateway, $config, $parsed->providerId, $payload);
        if ($row === null) {
            Log::warning('sms dlr: no matching message — dropped, counted', [
                'gateway_id' => $gateway->identifier(),
                'provider_id' => $parsed->providerId,
                'status' => $parsed->status->value,
                'metric' => 'sms.dlr.unknown_provider_id',
            ]);
            DB::transaction(fn () => $this->audit->record('sms.dlr.unknown_provider_id', 'sms_gateway', $gateway->identifier(), AuditActor::System, ['gateway_id' => $gateway->identifier(), 'provider_id' => (string) $parsed->providerId]));

            return 'unknown_provider_id';
        }

        $outcome = $this->machine->applyDlr(SmsDeliveryState::from($row->state), $parsed->status);

        DB::transaction(function () use ($row, $parsed, $outcome): void {
            /** @var list<array<string, mixed>> $dlrs */
            $dlrs = $row->raw_dlrs;
            $row->raw_dlrs = [...$dlrs, [
                'at' => (new \DateTimeImmutable)->format(DATE_ATOM),
                'raw' => $parsed->raw,
                'code' => $parsed->code,
                'status' => $parsed->status->value,
                'annotation' => $outcome->annotation,
            ]];

            if ($outcome->applied && $outcome->state->value !== $row->state) {
                $row->state = $this->machine->transition(SmsDeliveryState::from($row->state), $outcome->state)->value;
                $row->save();
                $this->lifecycle->publish('sms.'.$outcome->state->value, $row);
                $this->audit->record('sms.dlr.applied', 'sms_message', $row->identifier(), AuditActor::System, ['sms_message_id' => $row->identifier(), 'status' => $row->state, 'raw' => $parsed->raw]);
            } else {
                $row->save();
            }

            if ($outcome->contradiction) {
                // EC-04: precedence held the state; the raw code is retained above and FLAGGED here
                $this->audit->record('sms.dlr.contradiction', 'sms_message', $row->identifier(), AuditActor::System, ['sms_message_id' => $row->identifier(), 'kept_state' => $row->state, 'raw' => $parsed->raw, 'annotation' => (string) $outcome->annotation]);
            }
        });

        if (in_array($row->state, ['delivered', 'undelivered', 'expired'], true)) {
            $this->timers->cancel('sms_expiry', DueSet::member($row->tenant_id, $row->identifier()));
        }
        Log::info('sms dlr applied', ['sms_message_id' => $row->identifier(), 'annotation' => $outcome->annotation ?? 'applied', 'state' => $row->state, 'to_masked' => PhoneMask::forLog($row->phone)]);

        return $outcome->annotation ?? 'applied';
    }

    /** @param array<string, mixed> $payload */
    private function match(SmsGateway $gateway, GatewayConfig $config, ?string $providerId, array $payload): ?SmsMessage
    {
        if ($providerId !== null) {
            // an id that matches nothing is UNKNOWN (EC-03) — the fallback never
            // second-guesses a present-but-wrong id into somebody else's message
            return SmsMessage::query()->where('gateway_id', $gateway->identifier())->where('provider_id', $providerId)->first();
        }

        // The GeezSMS profile's documented fallback (§4): phone + message text, in memory only.
        $rawTo = $config->dlrString('phone_key') !== null ? ($payload[$config->dlrString('phone_key')] ?? null) : ($payload['phone'] ?? null);
        $sent = $payload['message'] ?? null;
        if (! is_string($rawTo) || $rawTo === '' || ! is_string($sent) || $sent === '') {
            return null;
        }
        $e164 = str_starts_with($rawTo, '+') ? $rawTo : '+'.$rawTo;

        return SmsMessage::query()
            ->where('gateway_id', $gateway->identifier())
            ->where('phone', $e164)
            ->where('rendered_body', $sent)
            ->orderByDesc('queued_at')
            ->first();
    }
}
