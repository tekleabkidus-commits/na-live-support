<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Metrics;

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

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
 * TEST TRAFFIC — the OTHER half of N-14 (P-N14, countersigned 2026-09-04, Doc 13
 * BR-07): the carrier is the WIDGET. A conversation opened under a widget whose
 * `test_mode` is on is sandbox traffic; its tickets are excluded by BOTH forms. The
 * predicate reads the widget through `conversations.channel_ref_id` (the widget id)
 * and is channel-BLIND by construction: a non-webchat conversation's channel_ref_id
 * names no widget row, so the join finds nothing (N-20 — no channel literal, no
 * conditional). Doc 15's dictionary inherits it; the Health tab's aggregates apply it.
 */
final class MetricExclusion
{
    /** The excluded statuses. One constant, both query forms read it. */
    private const EXCLUDED_STATUSES = [TicketStatus::Spam];

    public function excluded(Ticket $ticket): bool
    {
        if (in_array($ticket->status(), self::EXCLUDED_STATUSES, true)) {
            return true;
        }

        return $this->underTestModeWidget($ticket);
    }

    /**
     * The test-traffic half, row form: the ticket's conversation opened under a
     * test-mode widget. One query; the SQL form below is the identical predicate.
     */
    private function underTestModeWidget(Ticket $ticket): bool
    {
        return DB::table('conversations as c')
            ->join('widgets as w', DB::raw('w.id::text'), '=', 'c.channel_ref_id')
            ->where('c.id', $ticket->conversation_id)
            ->where('w.test_mode', true)
            ->exists();
    }

    /**
     * The same predicate as a WHERE fragment selecting INCLUDED rows, for Doc 15's
     * set-based aggregates. Values are inlined from the enum, never from input.
     */
    public function includedSql(string $column = 'status', string $conversationColumn = 'conversation_id'): string
    {
        $excluded = implode(', ', array_map(
            static fn (TicketStatus $status): string => sprintf("'%s'", $status->value),
            self::EXCLUDED_STATUSES,
        ));

        // Both halves of N-14, one fragment: the status half and the test-traffic half.
        // Column names are code-authored literals here (never input), as before.
        return sprintf(
            '(%s NOT IN (%s) AND NOT EXISTS (SELECT 1 FROM conversations mx_c JOIN widgets mx_w ON mx_w.id::text = mx_c.channel_ref_id WHERE mx_c.id = %s AND mx_w.test_mode))',
            $column,
            $excluded,
            $conversationColumn,
        );
    }
}
