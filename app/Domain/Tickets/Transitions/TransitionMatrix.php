<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

/**
 * FR-04's state machine AS DATA (T-01: "validates legality (FR-04 map as data)").
 *
 * Every legal edge is a row in one table below. Nothing branches on status anywhere
 * else in the codebase — SS-04's "no semantic drift" and AC-01's every-cell matrix
 * test both rest on there being exactly one place to read, and §21's build order puts
 * this table and its test before anything that composes on them.
 *
 * THE EDGES ARE READ OFF §4.2's NORMATIVE DIAGRAM, LITERALLY. FR-04: "Legal
 * transitions are exactly the edges above; all others rejected". Fifteen edges:
 *
 *   new              -> open              assign / claim (FR-05)
 *   new              -> spam              mark spam (FR-09)
 *   open             -> pending_customer  staff needs info
 *   open             -> pending_internal  await internal
 *   open             -> escalated         escalate (FR-23)
 *   open             -> resolved          resolve (FR-06)
 *   open             -> spam              mark spam (FR-09)
 *   pending_customer -> open              customer replies
 *   pending_internal -> open              answered
 *   escalated        -> open              de-escalate (FR-23)
 *   resolved         -> open              customer replies within the reopen window (FR-08)
 *   resolved         -> closed            auto-close timer (FR-07)
 *   closed           -> open              reopen within window, with permission (FR-08)
 *   spam             -> new               unmark within 7 days, restoring the prior status (FR-09)
 *   spam             -> open              unmark within 7 days, restoring the prior status (FR-09)
 *
 * TWO READINGS WORTH STATING, because a future session will wonder:
 *
 * 1. SPAM IS ENTERED FROM `new` AND `open` ONLY. In the diagram the mark-spam line
 *    joins the descenders of exactly those two columns. So marking a pending_customer
 *    ticket as spam is NOT an edge, and §8's "illegal targets absent, not disabled"
 *    means the panel simply never offers it there.
 * 2. SPAM'S UNMARK RETURNS TO {new, open} for the same reason — "prior status" can
 *    only be a status spam was reachable from.
 * 3. `closed` IS REACHED ONLY FROM `resolved`. FR-25's bulk `close` action therefore
 *    applies to resolved tickets, and a bulk close over an open ticket fails THAT ITEM
 *    with illegal_transition — which is exactly AC-07's honest per-item partial
 *    success, not an error in the batch.
 *
 * PERMISSIONS RIDE THE EDGE, not the target status, because two edges into `open` need
 * different keys (a de-escalation is not a reopen). Every key below is verified present
 * in the seeded catalog; A-9 files the mappings the document does not name inline.
 * NFR-03 forbids inventing keys.
 */
final class TransitionMatrix
{
    /**
     * from => to => edge metadata.
     *
     * `reason` marks FR-04's "reason where required (escalate, spam, staff
     * reopen-of-closed)". `min_reason_length` carries FR-23/BR-06's ≥ 10 characters,
     * which exists so an escalation is coachable rather than a silent shove.
     *
     * @var array<string, array<string, array{permission: string, reason: bool, min_reason_length: int}>>
     */
    private const EDGES = [
        'new' => [
            'open' => ['permission' => 'tickets.status.change', 'reason' => false, 'min_reason_length' => 0],
            'spam' => ['permission' => 'tickets.spam.mark', 'reason' => true, 'min_reason_length' => 0],
        ],
        'open' => [
            'pending_customer' => ['permission' => 'tickets.status.change', 'reason' => false, 'min_reason_length' => 0],
            'pending_internal' => ['permission' => 'tickets.status.change', 'reason' => false, 'min_reason_length' => 0],
            'escalated' => ['permission' => 'tickets.escalate', 'reason' => true, 'min_reason_length' => 10],
            'resolved' => ['permission' => 'tickets.status.change', 'reason' => false, 'min_reason_length' => 0],
            'spam' => ['permission' => 'tickets.spam.mark', 'reason' => true, 'min_reason_length' => 0],
        ],
        'pending_customer' => [
            'open' => ['permission' => 'tickets.status.change', 'reason' => false, 'min_reason_length' => 0],
        ],
        'pending_internal' => [
            'open' => ['permission' => 'tickets.status.change', 'reason' => false, 'min_reason_length' => 0],
        ],
        'escalated' => [
            // De-escalation is the escalation path in reverse; it is governed by the
            // same key rather than a new one (the catalog is closed — R-12/NFR-03).
            'open' => ['permission' => 'tickets.escalate', 'reason' => false, 'min_reason_length' => 0],
        ],
        'resolved' => [
            // FR-08's reopen-on-customer-reply. A customer message drives this, so the
            // actor is the system and no staff key is checked — see the service.
            'open' => ['permission' => 'tickets.reopen', 'reason' => false, 'min_reason_length' => 0],
            'closed' => ['permission' => 'tickets.close', 'reason' => false, 'min_reason_length' => 0],
        ],
        'closed' => [
            // FR-08: "Staff reopen of closed tickets requires tickets.reopen and a reason."
            'open' => ['permission' => 'tickets.reopen', 'reason' => true, 'min_reason_length' => 0],
        ],
        'spam' => [
            'new' => ['permission' => 'tickets.spam.mark', 'reason' => true, 'min_reason_length' => 0],
            'open' => ['permission' => 'tickets.spam.mark', 'reason' => true, 'min_reason_length' => 0],
        ],
    ];

    public function isLegal(TicketStatus $from, TicketStatus $to): bool
    {
        return isset(self::EDGES[$from->value][$to->value]);
    }

    /** @return array{permission: string, reason: bool, min_reason_length: int} */
    public function edge(TicketStatus $from, TicketStatus $to): array
    {
        $edge = self::EDGES[$from->value][$to->value] ?? null;

        if ($edge === null) {
            throw new IllegalTransition($from, $to);
        }

        return $edge;
    }

    /**
     * The legal next-states from here — §8's status control renders exactly this list
     * ("illegal targets absent, not disabled"), so the panel can never offer an edge
     * the server would refuse.
     *
     * @return list<TicketStatus>
     */
    public function legalTargets(TicketStatus $from): array
    {
        return array_map(
            static fn (string $value): TicketStatus => TicketStatus::from($value),
            array_keys(self::EDGES[$from->value]),
        );
    }

    /** @return list<array{from: TicketStatus, to: TicketStatus}> */
    public function allEdges(): array
    {
        $edges = [];
        foreach (self::EDGES as $from => $targets) {
            foreach (array_keys($targets) as $to) {
                $edges[] = ['from' => TicketStatus::from($from), 'to' => TicketStatus::from($to)];
            }
        }

        return $edges;
    }
}
