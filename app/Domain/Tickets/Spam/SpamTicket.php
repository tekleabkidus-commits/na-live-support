<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Spam;

use App\Domain\Conversations\Actions\ConversationBlocking;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * FR-09's spam disposition, both directions.
 *
 *   mark      tickets.spam.mark + reason (the machine's edge enforces both; spam is
 *             entered from new|open only — A-10's countersigned reading). The R-20
 *             trigger stamps spam_marked_at; this class records spam_marked_by (an
 *             actor id is the app's to write). Metric exclusion is NOT an act — it is
 *             MetricExclusion reading status = spam (N-14's one place). The dialog's
 *             "separate, separately-confirmed conversation block" is exactly that:
 *             a SEPARATE call to Doc 04's ConversationBlocking, its own event and
 *             audit trail — the separate confirmation is the dialog's obligation
 *             (§8); the separate act is this class's.
 *
 *   unmark    within 7 days of spam_marked_at (DB-time, inclusive boundary — the
 *             EC-02 posture): restores the PRIOR status read from the ticket's own
 *             event history ("history preserved in events", FR-24), which A-10
 *             guarantees is new or open; metrics re-include the moment status leaves
 *             spam, by the same one predicate. Day 8+: refused, terminal (EC-09) —
 *             the refusal explains terminality, and the customer's next message
 *             opens a FRESH ticket via the reopen consumer's terminal-spam rung
 *             (A-17).
 *
 * THE 7 DAYS ARE FIXED, not a setting: FR-09 states the number and registers no key;
 * SS-03's tunables (autoclose hours, reopen days) are named settings — this one is
 * not, and inventing a knob is inventing a requirement.
 */
final class SpamTicket
{
    public function __construct(
        private readonly TicketTransitionService $transitions,
        private readonly ConversationBlocking $blocking,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function mark(Ticket $ticket, User $actor, string $reason, bool $blockConversation = false): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $reason, $blockConversation): Ticket {
            // The machine: legality (new|open → spam), tickets.spam.mark, the reason.
            $this->transitions->transition($ticket, TicketStatus::Spam, TransitionContext::staff($actor, $reason));

            Ticket::query()->whereKey($ticket->identifier())->update([
                'spam_marked_by' => $actor->identifier(),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();

            $this->events->publish('ticket.spam_marked', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'conversation_blocked' => $blockConversation,
                'actor_type' => 'staff',
                'actor_id' => $actor->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.spam_marked',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'reason' => $reason,
                    'conversation_blocked' => $blockConversation,
                ],
                actorId: $actor->identifier(),
            );

            if ($blockConversation) {
                // The SEPARATE act (FR-09/Doc 04 FR-03): its own permission surface,
                // event and audit belong to Doc 04's service, not to this one.
                /** @var Conversation $conversation */
                $conversation = Conversation::query()->findOrFail($ticket->conversation_id);
                $this->blocking->block($conversation, $actor, 'spam disposition: '.$reason);
            }

            return $ticket;
        });
    }

    public function unmark(Ticket $ticket, User $actor, string $reason): Ticket
    {
        if ($ticket->status() !== TicketStatus::Spam) {
            throw new TransitionRefused('not_spam', 'this ticket is not marked as spam (FR-09).');
        }

        // EC-09's boundary, in the database's own arithmetic, inclusive (the EC-02
        // posture): day 7's last instant is IN, one second beyond is terminal.
        /** @var object{within: bool}|null $row */
        $row = DB::selectOne(
            'SELECT (clock_timestamp() <= spam_marked_at + make_interval(days => 7)) AS within
               FROM tickets WHERE id = ? AND spam_marked_at IS NOT NULL',
            [$ticket->identifier()],
        );

        if ($row === null || ! $row->within) {
            throw new TransitionRefused(
                'spam_terminal',
                'the 7-day unmark window has passed — spam is now terminal (FR-09/EC-09); the customer\'s next message opens a fresh ticket.',
            );
        }

        $prior = $this->priorStatus($ticket);

        return DB::transaction(function () use ($ticket, $actor, $reason, $prior): Ticket {
            // The machine again: spam → prior (A-10 pins prior ∈ {new, open}), with
            // the mandatory reason. The trigger clears spam_marked_at with the exit;
            // the actor column clears here. Metrics re-include by the same predicate
            // that excluded them — no flag to flip, no second reading (N-14).
            $this->transitions->transition($ticket, $prior, TransitionContext::staff($actor, $reason));

            Ticket::query()->whereKey($ticket->identifier())->update([
                'spam_marked_by' => null,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();

            $this->events->publish('ticket.spam_unmarked', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'restored_status' => $prior->value,
                'actor_type' => 'staff',
                'actor_id' => $actor->identifier(),
            ]);

            // SEC-03: "Spam-unmark … always audited with actor + old/new."
            $this->audit->record(
                actionKey: 'ticket.spam_unmarked',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'restored_status' => $prior->value,
                    'reason' => $reason,
                ],
                actorId: $actor->identifier(),
            );

            return $ticket;
        });
    }

    /**
     * FR-09's "restores prior status", read from the ticket's own event history —
     * FR-24's "history preserved in events" is what makes the answer durable, and
     * A-10's countersigned reading (spam entered from new|open only) is what makes
     * it one of exactly two values.
     */
    private function priorStatus(Ticket $ticket): TicketStatus
    {
        // `outbox` FIRST, then `events`: a just-marked ticket's event may not have
        // relayed yet (C-06), and an unmark must not block on relay lag. The outbox
        // keeps relayed rows until retention prunes them (their copies then live in
        // `events`), so the newest mark is always in the first table that answers —
        // a re-marked ticket's older mark can never shadow the newer one.
        $event = null;

        foreach (['outbox', 'events'] as $table) {
            /** @var object{payload: string}|null $event */
            $event = DB::table($table)
                ->where('name', 'ticket.status_changed')
                ->whereRaw("payload->>'ticket_id' = ?", [$ticket->identifier()])
                ->whereRaw("payload->>'new_status' = 'spam'")
                ->orderByDesc('id')
                ->first();

            if ($event !== null) {
                break;
            }
        }

        if ($event === null) {
            // Only a raw fixture can be spam with no marking event; refusing to guess
            // is the honest answer (the event stream is the history FR-24 names).
            throw new LogicException(sprintf(
                'ticket %s is spam but no ticket.status_changed event records the mark — cannot determine the prior status (FR-09/FR-24).',
                $ticket->identifier(),
            ));
        }

        /** @var array{old_status: string} $payload */
        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);

        return TicketStatus::from($payload['old_status']);
    }
}
