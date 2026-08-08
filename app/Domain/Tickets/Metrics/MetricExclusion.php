<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Metrics;

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Ticket;

/**
 * N-14's ONE place: "Spam and test traffic never count in any metric. Exclusion
 * applied in the metric dictionary, once."
 *
 * The metric DICTIONARY is Doc 15's; this class is the predicate it applies — defined
 * here, in the module that owns the state the predicate reads, so Doc 06's tests and
 * Doc 15's dictionary can never hold two readings of "excluded". FR-09's lifecycle
 * falls out of one line: marking spam excludes (status = spam), unmarking restores
 * (status returns to new|open — the trigger clears spam_marked_at with it), terminal
 * spam stays excluded forever (the status never changes again).
 *
 * BOTH FORMS, ONE TRUTH: excluded() answers for a loaded row; sql() is the identical
 * predicate for set-based dictionary queries (Doc 15's aggregates must not load rows
 * to filter them). AC-06's golden fixtures assert the first; the four-surface parity
 * test (N-14, Doc 15) will assert the two never drift by construction — sql() is
 * derived from the same constant the row form reads.
 *
 * Test traffic is the OTHER half of N-14 and it is not represented in the ticket
 * schema yet (no fixture/test flag exists on tickets); it enters this predicate when
 * Doc 15 defines its carrier. Recorded, not invented.
 */
final class MetricExclusion
{
    /** The excluded statuses. One constant, both query forms read it. */
    private const EXCLUDED_STATUSES = [TicketStatus::Spam];

    public function excluded(Ticket $ticket): bool
    {
        return in_array($ticket->status(), self::EXCLUDED_STATUSES, true);
    }

    /**
     * The same predicate as a WHERE fragment selecting INCLUDED rows, for Doc 15's
     * set-based aggregates. Values are inlined from the enum, never from input.
     */
    public function includedSql(string $column = 'status'): string
    {
        $excluded = implode(', ', array_map(
            static fn (TicketStatus $status): string => sprintf("'%s'", $status->value),
            self::EXCLUDED_STATUSES,
        ));

        return sprintf('%s NOT IN (%s)', $column, $excluded);
    }
}
