<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Reopen;

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Domain\Tickets\Transitions\TransitionResult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * FR-08's reopen, both doors, one set of rules.
 *
 *   CUSTOMER DOOR   a customer message on a `resolved` ticket reopens it
 *                   UNCONDITIONALLY (FR-10: "a customer message on a resolved ticket
 *                   reopens it rather than creating a duplicate — FR-08 takes
 *                   precedence over new-ticket creation"); on a `closed` ticket it
 *                   reopens within the window and yields to a successor beyond it.
 *
 *   STAFF DOOR      §4.2: "closed ──reopen (≤ window, perm)──► open". Same window,
 *                   plus `tickets.reopen` and a mandatory reason (the transition
 *                   edge enforces both). Beyond the window the answer is a refusal —
 *                   the closed ticket is done; a new ticket (FR-12) is the recourse.
 *
 * THE WINDOW IS EC-02's, EXACTLY: `reference_time <= closed_at + tickets.reopen.days`,
 * evaluated IN SQL so both operands are database time (C-11) — `closed_at` is the
 * R-20 trigger's stamp, and the reference is the customer MESSAGE's own `created_at`
 * (never the consumer's processing moment: a boundary reply must not fall out of its
 * window because a queue was slow) or, for the staff door, the database's now. The
 * comparison is `<=`: the boundary instant is IN, fixture-pinned by
 * ReopenAutoCloseTest. Interval arithmetic runs on a UTC session (C-03), so
 * `make_interval(days => 7)` is exactly 168 h across any DST date (R-03).
 *
 * The window is measured against the CURRENT setting at evaluation time. SS-03 pins
 * arm-time materialization for the AUTO-CLOSE timer's deadline; the reopen window
 * fires nothing and holds no armed state — it is a question asked of a boundary, and
 * the operator's current policy answers it.
 *
 * ASSIGNEE RECONCILIATION (FR-08): "assignee = previous if active else re-route via
 * Doc 11". Runs BEFORE the transition, in its transaction, so the ticket.reopened
 * event carries the reconciled outcome. An inactive assignee is handed back exactly
 * as EC-06 does it — assignee cleared, `ticket.assignee_deactivated` emitted for
 * Doc 11 to route when it exists. Applied on both doors: a staff reopen landing on a
 * deactivated assignee is the same orphaned-ticket hazard EC-06 exists to prevent.
 * "Re-notifies an active prior assignee" (AC-03) is the ticket.reopened event itself —
 * Doc 16 delivers it against the retained assignee.
 */
final class ReopenTicket
{
    public function __construct(
        private readonly TicketTransitionService $transitions,
        private readonly SettingsRepository $settings,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The customer door. Returns the reopened ticket, or null when the message falls
     * beyond a closed ticket's window — the caller (TicketReopenConsumer) owns the
     * FR-08 successor in that case.
     */
    public function reopenFromCustomerMessage(Ticket $ticket, string $messageId): ?Ticket
    {
        $status = $ticket->status();

        if ($status === TicketStatus::Closed && ! $this->withinWindow($ticket, $messageId)) {
            return null;
        }

        if ($status !== TicketStatus::Resolved && $status !== TicketStatus::Closed) {
            throw new LogicException(sprintf(
                'reopenFromCustomerMessage on a %s ticket — FR-08 reopens resolved or closed tickets only.',
                $status->value,
            ));
        }

        return DB::transaction(function () use ($ticket): Ticket {
            $this->reconcileAssignee($ticket);

            $result = $this->transitions->transition(
                $ticket,
                TicketStatus::Open,
                TransitionContext::system('fr08_customer_reply_within_window'),
            );

            return $result->ticket;
        });
    }

    /**
     * The staff door — window enforced here, permission (`tickets.reopen`) and the
     * mandatory reason enforced by the closed→open edge in the transition service.
     */
    public function reopenByStaff(Ticket $ticket, User $actor, string $reason): TransitionResult
    {
        if ($ticket->status() === TicketStatus::Closed && ! $this->withinWindow($ticket, null)) {
            throw new TransitionRefused(
                'reopen_window_passed',
                sprintf(
                    'the reopen window (%d days from closure) has passed; open a new ticket for this conversation instead (FR-08/§4.2).',
                    $this->windowDays(),
                ),
            );
        }

        return DB::transaction(function () use ($ticket, $actor, $reason): TransitionResult {
            $this->reconcileAssignee($ticket);

            return $this->transitions->transition($ticket, TicketStatus::Open, TransitionContext::staff($actor, $reason));
        });
    }

    /**
     * EC-02's comparison, in the database's own arithmetic. A null $messageId asks
     * against the database's now (the staff door); otherwise against the message's
     * DB-stamped created_at (the customer door).
     */
    private function withinWindow(Ticket $ticket, ?string $messageId): bool
    {
        if ($ticket->closed_at === null) {
            // A closed ticket with no closed_at never went through the machine (only
            // raw fixtures can look like this). No boundary exists to measure — the
            // fail-closed answer is "the window cannot be shown to be open".
            return false;
        }

        if ($messageId !== null) {
            /** @var object{within: bool}|null $row */
            $row = DB::selectOne(
                'SELECT (m.created_at <= t.closed_at + make_interval(days => ?)) AS within
                   FROM tickets t
                   JOIN messages m ON m.tenant_id = t.tenant_id AND m.id = ?
                  WHERE t.id = ?',
                [$this->windowDays(), $messageId, $ticket->identifier()],
            );
        } else {
            /** @var object{within: bool}|null $row */
            $row = DB::selectOne(
                'SELECT (clock_timestamp() <= t.closed_at + make_interval(days => ?)) AS within
                   FROM tickets t
                  WHERE t.id = ?',
                [$this->windowDays(), $ticket->identifier()],
            );
        }

        return $row !== null && $row->within;
    }

    private function windowDays(): int
    {
        $days = $this->settings->get('tickets.reopen.days');
        assert(is_int($days));

        return $days;
    }

    /**
     * FR-08: "assignee = previous if active else re-route via Doc 11". The retained
     * case is a no-op here — the assignee is already on the row; ticket.reopened
     * re-notifies them (Doc 16). The inactive case is EC-06's hand-back, one ticket.
     */
    private function reconcileAssignee(Ticket $ticket): void
    {
        $assigneeId = $ticket->assignee_id;

        if ($assigneeId === null) {
            return;
        }

        $assignee = User::query()->find($assigneeId);

        if ($assignee instanceof User && ! $assignee->isDisabled()) {
            return;
        }

        Ticket::query()->whereKey($ticket->identifier())->update([
            'assignee_id' => null,
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $ticket->refresh();

        $this->events->publish('ticket.assignee_deactivated', [
            'tenant_id' => $ticket->getAttribute('tenant_id'),
            'ticket_id' => $ticket->identifier(),
            'conversation_id' => $ticket->conversation_id,
            'previous_assignee_id' => $assigneeId,
        ]);

        $this->audit->record(
            actionKey: 'ticket.assignee_deactivated',
            entity: 'ticket',
            entityId: $ticket->identifier(),
            actorType: AuditActor::System,
            payload: [
                'ticket_id' => $ticket->identifier(),
                'previous_assignee_id' => $assigneeId,
            ],
        );
    }
}
