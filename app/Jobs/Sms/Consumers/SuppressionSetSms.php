<?php

declare(strict_types=1);

namespace App\Jobs\Sms\Consumers;

/**
 * `customer.suppression.set` (Doc 12's marketing suppression): the customer's
 * HELD non-essential rows become `skipped:marketing_suppressed` — a held
 * announcement never slips out after the suppression landed. Essential
 * service-continuity events (ticket_resolved, window_reengagement) stay held
 * (FR-15's law); NEW sends are the live guard's problem, read at send time.
 * Naturally idempotent by state (a redelivery finds no held rows). Reads:
 * tenant_id, customer_id.
 */
final class SuppressionSetSms extends SmsEventConsumer
{
    public function handle(): void
    {
        $consumed = $this->consumedEvent(['customer.suppression.set']);
        if ($consumed === null) {
            return;
        }
        $customerId = $consumed['payload']['customer_id'];
        if (! is_string($customerId)) {
            return;
        }

        $this->skipHeldNonEssential($customerId, 'marketing_suppressed');
    }
}
