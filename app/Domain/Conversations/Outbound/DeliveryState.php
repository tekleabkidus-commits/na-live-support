<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

/**
 * FR-13's states, exactly the messages CHECK constraint's roster. The backing
 * values ARE the column values and the event `state` identifiers — renaming one
 * is a frozen-public-contract change requiring series-wide review (§21).
 */
enum DeliveryState: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case FailedRetryable = 'failed_retryable';
    case FailedFinal = 'failed_final';
    case Canceled = 'canceled';
}
