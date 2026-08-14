<?php

declare(strict_types=1);

namespace App\Domain\Customers\Rollups;

use App\Domain\Tickets\Metrics\MetricExclusion;
use Illuminate\Support\Facades\DB;

/**
 * FR-03's rollups, the Doc 07 projector idiom applied: DENORMALIZED,
 * EVENT-MAINTAINED, RECOMPUTED FROM TRUTH — an event names WHICH customer to
 * recompute; values never come from payloads, so the writer is idempotent by
 * construction (C-09) and the weekly checker (NFR-05) is the same code path
 * asking the same question.
 *
 * N-14 rides MetricExclusion's ONE predicate (sql form) — spam tickets count
 * nowhere, and when Doc 15 defines the test-traffic carrier it lands there,
 * not here.
 *
 * `last_contact_at` is PINNED-PROVENANCE (SCHEMA AMENDMENT 2; A-33/R-19b):
 * a BYTE-COPY of the newest non-quarantined customer message's own DB-time
 * `messages.created_at` — never a fresh clock read, because a wall-clock
 * write here would let app-server skew reorder "last contact" against the
 * thread itself. Sabotage amendment #16's first control asserts the
 * byte-equality; switching this SELECT to now() is the sabotage.
 *
 * `contacts_30d_count` (countersign ruling 3): the WINDOW IS IN THE NAME —
 * tickets created in the trailing 30 days, N-14-excluded, compared in SQL
 * against now() (C-11; the A-3 precedent). Doc 15 owns `repeat_contact_rate`
 * and may redefine the window — a redefinition is a recompute, not a
 * migration ([D15]).
 *
 * `satisfaction_avg`/`satisfaction_count` are DELIBERATELY ABSENT from this
 * recompute: their inputs are Doc 12's ([D12], fixture/seeder-fed until CSAT
 * capture exists). The drift checker skips them for the same reason, stated
 * there.
 *
 * §12 budgets each handler at ≤ 5 ms (they run on every ticket/CSAT event);
 * the recompute is four indexed aggregates on one customer's rows —
 * tests/Performance/CustomerBudgetsTest prints the dev-scale figure.
 */
final class CustomerRollups
{
    public function __construct(private readonly MetricExclusion $exclusion) {}

    public function recompute(string $customerId): void
    {
        $included = $this->exclusion->includedSql('tickets.status');

        DB::statement(
            <<<SQL
            UPDATE customers c
               SET tickets_count = (
                       SELECT count(*) FROM tickets
                        WHERE tickets.customer_id = c.id AND {$included}
                   ),
                   open_tickets = (
                       SELECT count(*) FROM tickets
                        WHERE tickets.customer_id = c.id AND {$included}
                          AND tickets.status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated')
                   ),
                   contacts_30d_count = (
                       SELECT count(*) FROM tickets
                        WHERE tickets.customer_id = c.id AND {$included}
                          AND tickets.created_at >= now() - interval '30 days'
                   ),
                   last_contact_at = (
                       SELECT max(m.created_at)
                         FROM messages m
                         JOIN conversations cv ON cv.id = m.conversation_id
                        WHERE cv.customer_id = c.id
                          AND m.author_type = 'customer'
                          AND m.quarantined = false
                   )
             WHERE c.id = ?
            SQL,
            [$customerId],
        );
    }

    /**
     * The drift question, answered without writing (NFR-05's checker half):
     * stored vs recomputed for one customer, named columns only.
     *
     * @return list<string> drifted column names; empty = no drift
     */
    public function drift(string $customerId): array
    {
        $included = $this->exclusion->includedSql('tickets.status');

        /** @var object{tickets_drift: bool, open_drift: bool, window_drift: bool, contact_drift: bool}|null $row */
        $row = DB::selectOne(
            <<<SQL
            SELECT c.tickets_count IS DISTINCT FROM t.total   AS tickets_drift,
                   c.open_tickets IS DISTINCT FROM t.open     AS open_drift,
                   c.contacts_30d_count IS DISTINCT FROM t.recent AS window_drift,
                   c.last_contact_at IS DISTINCT FROM m.newest AS contact_drift
              FROM customers c
              LEFT JOIN LATERAL (
                       SELECT count(*) FILTER (WHERE {$included}) AS total,
                              count(*) FILTER (WHERE {$included} AND tickets.status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated')) AS open,
                              count(*) FILTER (WHERE {$included} AND tickets.created_at >= now() - interval '30 days') AS recent
                         FROM tickets WHERE tickets.customer_id = c.id
                   ) t ON true
              LEFT JOIN LATERAL (
                       SELECT max(m2.created_at) AS newest
                         FROM messages m2
                         JOIN conversations cv ON cv.id = m2.conversation_id
                        WHERE cv.customer_id = c.id AND m2.author_type = 'customer' AND m2.quarantined = false
                   ) m ON true
             WHERE c.id = ?
            SQL,
            [$customerId],
        );

        if ($row === null) {
            return [];
        }

        $drifted = [];
        foreach (['tickets_drift' => 'tickets_count', 'open_drift' => 'open_tickets', 'window_drift' => 'contacts_30d_count', 'contact_drift' => 'last_contact_at'] as $flag => $column) {
            if ((bool) $row->{$flag}) {
                $drifted[] = $column;
            }
        }

        return $drifted;
    }
}
