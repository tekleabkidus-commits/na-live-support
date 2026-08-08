<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Timers;

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The T-03 sweep: close what is genuinely due, drop what is stale, and LOSE to any
 * customer reply (Doc 06 FR-07, EC-01).
 *
 * EC-01 IS THE HEART OF THIS CLASS, so its mechanism is worth stating precisely.
 * "Customer replies at the exact auto-close sweep moment → event-driven cancel wins
 * (sweep re-checks in-transaction); no close-then-reopen flicker."
 *
 * The re-check is ONE locking statement inside the closing transaction, and it answers
 * two different races:
 *
 *   1. THE REOPEN ALREADY LANDED (TicketReopenConsumer committed first, or a staff
 *      action moved the ticket): the row's status is no longer `resolved`, the guard
 *      refuses, the member is dropped. The guarded write in TicketRepository would
 *      catch this too — two layers, same answer.
 *
 *   2. THE REPLY IS PERSISTED BUT ITS CONSUMER HAS NOT RUN YET. This is the race the
 *      status check alone CANNOT see — the ticket still reads `resolved` — and it is
 *      why FR-07 says auto-close follows hours "of customer SILENCE", not hours on a
 *      clock. The statement checks for any customer message in the conversation at or
 *      after `resolved_at` (both DB times, C-11). One exists → the timer is cancelled
 *      and NOTHING closes; the consumer will reopen from `resolved` when it gets
 *      there. Without this check the sweep would close first and the lagging consumer
 *      would reopen from `closed` — converging on the same final state THROUGH the
 *      flicker EC-01 forbids, complete with a phantom closed interval in the FR-24
 *      accumulators and a spurious ticket.closed on the bus.
 *
 * The lock (FOR UPDATE) makes the check and the close one atomic unit: a reply
 * committing between them would otherwise re-create the race inside the fix. An
 * in-flight InboundPersister transaction holding this conversation does not block us —
 * messages insert, tickets lock, no overlap — but its message either committed before
 * our statement (we see it, we cancel) or commits after our transaction (the consumer
 * reopens a closed ticket within its window, which is FR-08's ordinary path, not
 * flicker: the reply genuinely arrived after the close).
 *
 * BR-07: `channel_unreachable` never auto-closes. The transition service refuses the
 * system actor (its invariant, not ours); the sweep drops the candidate and leaves the
 * armed column visible for Doc 12's pause/resume policy to own when it lands.
 */
final class TicketTimerSweep
{
    use RunsWithinTenant;

    public function __construct(
        private readonly TicketTimers $timers,
        private readonly TicketTransitionService $transitions,
    ) {}

    /**
     * One pass. Scheduled every minute; also callable ad hoc (tests, ops).
     *
     * @return array{closed: int, cancelled: int, dropped: int, deferred: int, held: int}
     */
    public function sweep(int $limit = 500): array
    {
        $result = ['closed' => 0, 'cancelled' => 0, 'dropped' => 0, 'deferred' => 0, 'held' => 0];

        foreach ($this->timers->due($limit) as $member) {
            [$tenantId] = $this->timers->parseMember($member);

            $outcome = $this->withinTenant($tenantId, fn (): string => $this->sweepOne($member));
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return 'closed'|'cancelled'|'dropped'|'deferred'|'held' */
    private function sweepOne(string $member): string
    {
        [, $ticketId] = $this->timers->parseMember($member);

        return DB::transaction(function () use ($member, $ticketId): string {
            // THE IN-TRANSACTION RE-CHECK (EC-01). One statement, row locked, all
            // three answers from DB time: is it still armed, is it actually due,
            // and has the customer already broken the silence?
            /** @var object{status: string, is_due: bool, customer_replied: bool}|null $row */
            $row = DB::selectOne(
                "SELECT t.status,
                        (t.auto_close_at IS NOT NULL AND t.auto_close_at <= clock_timestamp()) AS is_due,
                        (t.resolved_at IS NOT NULL AND EXISTS (
                            SELECT 1 FROM messages m
                             WHERE m.tenant_id = t.tenant_id
                               AND m.conversation_id = t.conversation_id
                               AND m.author_type = 'customer'
                               AND m.created_at >= t.resolved_at
                        )) AS customer_replied
                   FROM tickets t
                  WHERE t.id = ?
                    FOR UPDATE OF t",
                [$ticketId],
            );

            if ($row === null || $row->status !== TicketStatus::Resolved->value) {
                // Stale candidate: the ticket moved on (or never existed here). The
                // column truth was already cleared by whatever moved it.
                $this->timers->remove($member);

                return 'dropped';
            }

            /** @var Ticket $ticket */
            $ticket = Ticket::query()->findOrFail($ticketId);

            if ($row->customer_replied) {
                // EC-01: the event-driven cancel WINS. The reply is in the database,
                // so the silence FR-07 counts never completed — cancel the timer and
                // close nothing. TicketReopenConsumer reopens from `resolved`, which
                // is the flicker-free path.
                $this->timers->cancelAutoClose($ticket);

                return 'cancelled';
            }

            if (! $row->is_due) {
                // Candidate score and column disagree (a re-arm raced the sweep, or a
                // rebuild raced a settings-driven re-resolve). The column is truth:
                // put the candidate back at its real deadline.
                $deadline = $ticket->auto_close_at;
                if ($deadline !== null) {
                    $this->timers->add($member, (int) $deadline->format('Uu'));
                }

                return 'deferred';
            }

            try {
                // FR-07: the transition service is the ONLY door (NFR-03). Its own
                // guarded write re-checks the status one more time, its trigger stamps
                // closed_at, its hooks emit ticket.closed and consume this timer.
                $this->transitions->transition(
                    $ticket,
                    TicketStatus::Closed,
                    TransitionContext::system('fr07_auto_close'),
                );
            } catch (TransitionRefused $refused) {
                // BR-07's unreachable hold, in practice. Drop the candidate — every
                // sweep re-refusing forever is noise, not safety — and LEAVE the
                // column armed: the state stays visible, and Doc 12's pause policy
                // owns resumption (EC-10).
                Log::info('ticket timer held: auto-close refused', [
                    'ticket_id' => $ticketId,
                    'code' => $refused->refusalCode,
                ]);
                $this->timers->remove($member);

                return 'held';
            }

            $this->timers->remove($member);

            return 'closed';
        });
    }
}
