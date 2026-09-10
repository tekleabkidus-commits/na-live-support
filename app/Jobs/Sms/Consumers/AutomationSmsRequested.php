<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use App\Domain\Sms\Catalog\SendableEvent;
use Illuminate\Support\Facades\Log;

/**
 * `automation.sms.requested` (Doc 12 FR-03's send-SMS arm, BR-04): the payload
 * carries a TEMPLATE REFERENCE and identifiers — no text key exists
 * (ExternalArmsTest holds that closed). `template_ref` is an FR-01
 * sendable-event key (STOP-H as filed); the per-REF toggle is checked first.
 *
 * An unknown ref: the amendment filed "the row is skipped:unknown_template_ref"
 * — but a row's `event` column is CHECK-bound to the eleven template events,
 * and the one storable-but-unsendable value (`optout_confirmation`) cannot walk
 * the one EnqueueSms door (it is not a SendableEvent). So an unknown ref is a
 * LOGGED, COUNTED drop (`metric: sms.automation.unknown_template_ref`) and no
 * row exists — the physics-honest reading, FLAGGED for the advisor at B✓.
 * Reads: tenant_id, rule_id, execution_id, ticket_id, customer_id, template_ref.
 */
final class AutomationSmsRequested extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['automation.sms.requested']);
        if ($consumed === null) {
            return;
        }
        $ref = $consumed['payload']['template_ref'] ?? null;
        $ticketId = $consumed['payload']['ticket_id'] ?? null;
        $payloadCustomerId = $consumed['payload']['customer_id'] ?? null;
        if (! is_string($ticketId)) {
            return;
        }

        $event = is_string($ref) ? SendableEvent::tryFrom($ref) : null;
        if ($event === null) {
            Log::warning('sms automation: unknown template_ref — dropped, counted', [
                'event_id' => $this->eventId,
                'rule_id' => $consumed['payload']['rule_id'] ?? null,
                'execution_id' => $consumed['payload']['execution_id'] ?? null,
                'template_ref' => is_string($ref) ? $ref : gettype($ref),
                'metric' => 'sms.automation.unknown_template_ref',
            ]);

            return;
        }
        if (! $this->toggleOn($event) || $this->alreadyProduced()) {
            return;
        }

        $this->enqueueForTicket($event, $ticketId, is_string($payloadCustomerId) ? $payloadCustomerId : null);
    }
}
