<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use LogicException;

/**
 * AC-02's other half: every transition NOT in the FR-13 diagram is rejected, by
 * name, loudly. A LogicException because an illegal transition is always a caller
 * bug — the machine's table is the frozen contract, not a suggestion.
 */
final class IllegalDeliveryTransition extends LogicException
{
    public static function make(string $from, string $to): self
    {
        return new self(sprintf(
            'delivery transition %s → %s is not in the FR-13 state machine. The machine is a frozen '
            .'public contract (§21): if a new edge seems needed, that is a series-wide review, not an edit.',
            $from,
            $to,
        ));
    }
}
