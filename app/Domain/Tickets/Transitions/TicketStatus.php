<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

/**
 * The eight ticket statuses (Doc 06 §4.2), PLATFORM-FIXED in v1 (risk R-01).
 *
 * "One more status" is the erosion R-01 names, and the answer the document gives is
 * that future per-department display VOCABULARIES (§19) alias these eight — the
 * machine itself never forks. A ninth case here would also have to pass the
 * `tickets_status_check` CHECK constraint, so the schema and this enum have to be
 * changed together and deliberately.
 */
enum TicketStatus: string
{
    case New = 'new';
    case Open = 'open';
    case PendingCustomer = 'pending_customer';
    case PendingInternal = 'pending_internal';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Spam = 'spam';

    /**
     * FR-10's open family, DEFINED rather than assumed: {new, open, pending_customer,
     * pending_internal, escalated}.
     *
     * `resolved` is deliberately NOT here. That single fact is what makes FR-08's
     * reopen the path a customer's follow-up takes instead of a duplicate ticket
     * (BR-05: reopen beats duplicate), and it is mirrored exactly by the T-02 partial
     * unique index's WHERE clause — one definition, two enforcement layers.
     *
     * @return list<self>
     */
    public static function openFamily(): array
    {
        return [self::New, self::Open, self::PendingCustomer, self::PendingInternal, self::Escalated];
    }

    public function isOpenFamily(): bool
    {
        return in_array($this, self::openFamily(), true);
    }
}
