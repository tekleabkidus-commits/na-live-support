<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Retention;

/**
 * How inbound traffic for a tenant is stored right now (Doc 01 BR-05).
 */
enum InboundAccrual
{
    /** Store the message in full — the normal case, and the first ≤N suspended days. */
    case Full;

    /**
     * ACK to the channel, store only count + last pointer (ColdSummaryStore).
     * BR-05's bounded-liability mode for long-suspended tenants.
     */
    case ColdSummary;
}
