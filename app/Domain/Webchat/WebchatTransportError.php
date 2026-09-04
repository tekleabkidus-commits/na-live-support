<?php

declare(strict_types=1);

namespace App\Domain\Webchat;

use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use RuntimeException;

/**
 * A classified transport failure surfaced by the push door (Doc 04 FR-13's
 * table, carried as data so the adapter never inspects message strings).
 * Production raises it from WebchatPusher's own guards; the conformance
 * subclass scripts it to drive every class the channel can genuinely produce.
 */
final class WebchatTransportError extends RuntimeException
{
    public function __construct(
        public readonly DeliveryFailureReason $reason,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('webchat transport: '.$reason->value);
    }
}
