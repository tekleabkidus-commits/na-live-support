<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use RuntimeException;

/**
 * FR-04's refusal: `illegal_transition {from, to}`. The stable problem-details code
 * (C-04) is `illegal_transition`; `from` and `to` ride as fields so the UI can say
 * which edge was refused (§8: the panel renders legal next-states only, so reaching
 * this from the product surface means something raced or an API caller guessed).
 */
final class IllegalTransition extends RuntimeException
{
    public const CODE = 'illegal_transition';

    public function __construct(
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
    ) {
        parent::__construct(sprintf(
            'illegal_transition: %s -> %s is not an edge of the FR-04 state machine.',
            $from->value,
            $to->value,
        ));
    }
}
