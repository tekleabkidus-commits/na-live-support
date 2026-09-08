<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

use LogicException;

/** A pipeline transition FR-12 does not draw. The edge that does not exist is the load-bearing one. */
final class IllegalSmsTransition extends LogicException
{
    public static function from(SmsDeliveryState $from, SmsDeliveryState $to): self
    {
        return new self(sprintf('sms delivery state %s → %s is not a transition FR-12 draws', $from->value, $to->value));
    }
}
