<?php

declare(strict_types=1);

namespace App\Domain\Sms\Catalog;

/**
 * Doc 14 T-04 / NFR-02 / BR-05 — the three-lane priority: transactional >
 * re-engagement > broadcast, implemented as SEPARATE Horizon queues drained by
 * weighted workers on the same gateway buckets (the S14 Horizon family
 * amendment: one queue per supervisor, Doc 04 FS-03's physics; every queue
 * is `sms-` prefixed — the S0 family's bare queue name is renamed by the
 * amendment, and N-20's grep reads this namespace too). The backing value is
 * the queue name and the `sms_messages.lane` column value.
 */
enum Lane: string
{
    case Transactional = 'sms-transactional';
    case Reengagement = 'sms-reengagement';
    case Broadcast = 'sms-broadcast';

    /** The weight the amendment gives each supervisor in production (3/2/1). */
    public function weight(): int
    {
        return match ($this) {
            self::Transactional => 3,
            self::Reengagement => 2,
            self::Broadcast => 1,
        };
    }
}
