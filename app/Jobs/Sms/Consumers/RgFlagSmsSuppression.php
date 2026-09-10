<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

use Illuminate\Support\Facades\Log;

/**
 * The FR-15 contract (Doc14/SmsSuppression in the catalog): `customer.rg_flag.set`
 * re-evaluates the customer's HELD non-essential rows to `skipped:rg_flagged`
 * (N-10's neighborhood: a flagged player gets service continuity, never
 * promotion); `customer.rg_flag.cleared` revives NOTHING — the guard runs live
 * at send anyway — the consumer exists so the declaration is honoured and the
 * fact is logged. Reads: tenant_id, customer_id (both events).
 */
final class RgFlagSmsSuppression extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['customer.rg_flag.set', 'customer.rg_flag.cleared']);
        if ($consumed === null) {
            return;
        }
        $customerId = $consumed['payload']['customer_id'];
        if (! is_string($customerId)) {
            return;
        }

        if ($consumed['name'] === 'customer.rg_flag.cleared') {
            Log::info('sms rg suppression: flag cleared — nothing revived (the live guard decides at send)', ['event_id' => $this->eventId, 'customer_id' => $customerId]);

            return;
        }

        $this->skipHeldNonEssential($customerId, 'rg_flagged');
    }
}
