<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/**
 * Doc 14 FR-03 / T-06 — every skip (and hold) names its reason; the reason is
 * the `sms_messages.skip_reason` column value, the `reason` on `sms.skipped`
 * / `sms.held`, and the `guard_log` entry. The three module-level reasons
 * are A-67's pre-guards (Doc 16 FR-11's `feature_disabled`; B6-S16's
 * hold-all-sends; §8's "SMS is off until a gateway exists").
 */
enum GuardReason: string
{
    // pre-guards (A-67), evaluated before FR-03's six
    case FeatureDisabled = 'feature_disabled';
    case HoldAllSends = 'hold_all_sends';
    case NoGateway = 'no_gateway';

    // FR-03, in order
    case NoPhone = 'no_phone';
    case OptedOut = 'opted_out';
    case Blocked = 'blocked';
    case RgFlagged = 'rg_flagged';
    case MarketingSuppressed = 'marketing_suppressed';
    case Duplicate = 'duplicate';
    case QuietHours = 'quiet_hours';
}
