<?php

declare(strict_types=1);

namespace App\Domain\Sms\Broadcasts;

/**
 * EC-11's wall as a SEAM (the chunk job consults it before each chunk): at
 * step 10 it always allows — the monthly-segment quota case is P-QUOTA-SMS,
 * which lands at step 11 as countersigned. Step 11 replaces `headroom()`'s
 * body with `QuotaMeter::headroom(Quota::SmsSegmentsPerMonth)` and the 5 %
 * transactional reserve (BR-05's second half); the pause → `paused_quota` →
 * resume STATE MACHINE is already provable through this seam
 * (`BroadcastQuotaPauseTest` binds a gate that refuses, then allows).
 */
class BroadcastQuotaGate
{
    /** BR-05's second half at the wall: broadcasts never eat the last 5 % (the transactional reserve). */
    public const TRANSACTIONAL_RESERVE_FRACTION = 0.05;

    /**
     * True when a broadcast chunk may send now. Step 11 (P-QUOTA-SMS) fills
     * this from the meter; until then a broadcast is never quota-blocked
     * (stated in the step-10 record — the schema's `paused_quota` state and
     * the seam exist, the meter case does not yet).
     */
    public function __construct(private readonly \App\Support\Quota\QuotaMeter $meter) {}

    public function allows(): bool
    {
        try {
            $headroom = $this->meter->headroom(\App\Support\Quota\Quota::SmsSegmentsPerMonth);
        } catch (\Throwable) {
            return true; // a metering outage never blocks an in-flight broadcast (the send path is not a gate)
        }
        $remaining = $headroom['limit'] - $headroom['used'];

        // BR-05: broadcasts stop before the last 5% so transactional sends keep their headroom
        return $remaining > (int) ceil($headroom['limit'] * self::TRANSACTIONAL_RESERVE_FRACTION);
    }
}
