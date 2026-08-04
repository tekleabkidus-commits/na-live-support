<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Storage;

use RuntimeException;

/**
 * The one-open-ticket invariant's refusal (Doc 04 FR-04/BR-05): a second open ticket
 * was attempted on a conversation that already has one. Doc 06 must TRANSITION the
 * existing ticket, never duplicate it. The stable problem code is `open_ticket_exists`
 * (C-04) — Doc 06's ticket routes render it; this session only enforces it.
 */
final class OpenTicketExists extends RuntimeException
{
    public const CODE = 'open_ticket_exists';

    public function __construct(
        public readonly string $conversationId,
        public readonly string $existingTicketId,
    ) {
        parent::__construct(sprintf(
            "conversation %s already has open ticket %s ('%s') — transition it, do not duplicate (BR-05).",
            $conversationId,
            $existingTicketId,
            self::CODE,
        ));
    }
}
