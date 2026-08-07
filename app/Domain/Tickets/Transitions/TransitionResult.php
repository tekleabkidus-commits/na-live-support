<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use App\Models\Ticket;

/**
 * What a transition did. `changed = false` is EC-07's documented idempotency — a
 * SUCCESS in which nothing moved, either because the caller asked for the status the
 * ticket already had or because another transaction reached the same target first.
 *
 * Callers must be able to tell the two apart from the outside: bulk (FR-25) counts a
 * no-op as `ok`, while the UI uses it to refresh rather than to show a change.
 */
final readonly class TransitionResult
{
    private function __construct(
        public Ticket $ticket,
        public TicketStatus $from,
        public TicketStatus $to,
        public bool $changed,
    ) {}

    public static function changed(Ticket $ticket, TicketStatus $from, TicketStatus $to): self
    {
        return new self($ticket, $from, $to, true);
    }

    public static function noop(Ticket $ticket, TicketStatus $status): self
    {
        return new self($ticket, $status, $status, false);
    }
}
