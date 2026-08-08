<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Tickets\Storage\TicketRepository;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\VerdictSource;
use Illuminate\Support\Facades\DB;

/**
 * THE SOLE STATUS WRITER (Doc 06 T-01, NFR-03) — one path, in one transaction:
 *
 *   permission (Doc 03) -> legality (FR-04, as data) -> invariants (FR-10/BR-07)
 *   -> timestamps (FR-24) -> persist + outbox
 *
 * §21 puts this class and AC-01's every-cell matrix test before everything that
 * composes on them, and that is the order this session built them in. Every operation
 * that follows — resolve, escalate, spam, merge, split, bulk — reaches status through
 * here or not at all.
 *
 * ON THE PIPELINE'S ORDER. T-01's diagram lists permission before legality. The edge
 * lookup necessarily happens first, because the permission KEY is a property of the
 * edge (a de-escalation is not a reopen, and they need different keys), and an illegal
 * transition has no edge and therefore no key to check. Nothing is read or written
 * before the permission check, and the information an early illegal_transition reveals
 * is the published state machine itself (Doc 06 §4.2) — not a leak. Every other stage
 * runs in the documented order.
 *
 * ON TIMESTAMPS. This class computes none of FR-24's values, deliberately. The app role
 * cannot write those columns at all (R-19b), and the database trigger stamps them from
 * the same UPDATE statement that changes the status (ruling R-20). "Computed
 * transactionally with the transition, never derived later" is therefore structural:
 * there is no code path, here or anywhere, that could derive them afterwards.
 */
final class TicketTransitionService
{
    public function __construct(
        private readonly TransitionMatrix $matrix,
        private readonly TicketRepository $tickets,
        private readonly ConversationRepository $conversations,
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly TicketTimers $timers,
    ) {}

    public function transition(Ticket $ticket, TicketStatus $to, TransitionContext $context): TransitionResult
    {
        $from = $ticket->status();

        // EC-07 — a same-state call is a documented SUCCESS, not an error. Two agents
        // resolving at once must not produce an error modal for the second one; the UI
        // just refreshes. Nothing is written, so no event and no audit entry either:
        // an event stream that records non-changes is a stream Doc 15 has to filter.
        if ($from === $to) {
            return TransitionResult::noop($ticket, $from);
        }

        $edge = $this->matrix->edge($from, $to);          // FR-04 — throws IllegalTransition
        $this->assertPermitted($edge, $context);          // Doc 03
        $this->assertReason($edge, $context);             // FR-04 / FR-23 / BR-06
        $this->assertInvariants($ticket, $to, $context);  // BR-07

        return DB::transaction(function () use ($ticket, $from, $to, $context): TransitionResult {
            // FR-10, reconciled with Doc 04's shipped layer (inherited row 5): the
            // conversation's `open_ticket_id` column owns "this conversation names at
            // most one open ticket" and refuses with open_ticket_exists; the T-02
            // partial unique index owns "at most one open-family ROW". This service
            // drives the first; the index survives anything that bypasses it.
            //
            // linked_work tickets (FR-21 split targets) take neither: they are the one
            // sanctioned multi-open case, so they must not occupy the single slot.
            if (! $ticket->linked_work) {
                $conversation = $this->conversationFor($ticket);

                if ($from->isOpenFamily() && ! $to->isOpenFamily()) {
                    $this->conversations->releaseOpenTicket($conversation, $ticket->identifier());
                } elseif (! $from->isOpenFamily() && $to->isOpenFamily()) {
                    $this->conversations->claimOpenTicket($conversation, $ticket->identifier());
                }
            }

            $written = $this->tickets->writeStatus($ticket, $from, $to, [
                'first_response_message_id' => $context->signalMessageId,
                'agent_first_response_message_id' => $context->agentSignalMessageId,
            ]);

            if (! $written) {
                // The guard matched nothing: another transaction moved this ticket
                // between our read and our write. Re-read and answer from the truth.
                $ticket->refresh();

                if ($ticket->status() === $to) {
                    return TransitionResult::noop($ticket, $to);   // EC-07, the racing half
                }

                // It went somewhere else entirely — re-evaluate against reality rather
                // than forcing a write that was legal only against a stale status.
                throw new IllegalTransition($ticket->status(), $to);
            }

            $ticket->refresh();

            $this->events->publish('ticket.status_changed', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'old_status' => $from->value,
                'new_status' => $to->value,
                'actor_type' => $context->actor->value,
                'actor_id' => $context->user?->identifier(),
            ]);

            // SEC-03: actor + old/new, every time. The document names spam-unmark,
            // staff reopen and escalation reasons as always-audited; auditing EVERY
            // transition is the superset, and the audit-coverage registry requires a
            // state-mutating action to have an entry regardless.
            $this->audit->record(
                actionKey: 'ticket.status_changed',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: $context->actor->auditActor(),
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    'old_status' => $from->value,
                    'new_status' => $to->value,
                    'reason' => $context->reason,
                ],
                actorId: $context->user?->identifier(),
            );

            $this->applyLifecycleEffects($ticket, $from, $to, $context);

            return TransitionResult::changed($ticket, $from, $to);
        });
    }

    /**
     * The lifecycle semantics that ride EVERY entry into / exit from the timer-bearing
     * states, IN the sole writer so no door can drift (SS-04): the resolve action, the
     * sweep, the reopen consumer, step 7's bulk — all of them pass through here and
     * get identical events and identical timer behaviour.
     *
     *   into resolved            arm the FR-07 auto-close timer (T-03; deadline
     *                            materialized per SS-03) + ticket.resolved (FR-06's
     *                            CSAT/SMS signal — A-8: doubles as Doc 14's)
     *   out of resolved          the armed timer is meaningless (consumed by the
     *                            close, or cancelled by the reopen) — clear it
     *   into closed              ticket.closed (FR-08's window runs from closed_at)
     *   resolved|closed -> open  a REOPEN (FR-08): reopened_count++ and
     *                            ticket.reopened. spam -> open is deliberately NOT
     *                            here — FR-09's unmark restores, it does not reopen.
     *
     * reopened_count counts BOTH reopen doors (customer-message and staff): FR-08
     * introduces the counter while describing the customer door and the field itself
     * is "reopens of this ticket" (FR-01) — a staff reopen is one of those.
     */
    private function applyLifecycleEffects(Ticket $ticket, TicketStatus $from, TicketStatus $to, TransitionContext $context): void
    {
        $actor = [
            'actor_type' => $context->actor->value,
            'actor_id' => $context->user?->identifier(),
        ];

        if ($to === TicketStatus::Open && in_array($from, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            Ticket::query()->whereKey($ticket->identifier())->update([
                'reopened_count' => DB::raw('reopened_count + 1'),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();

            $this->events->publish('ticket.reopened', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'from_status' => $from->value,
                'reopened_count' => $ticket->reopened_count,
                'assignee_id' => $ticket->assignee_id,
                ...$actor,
            ]);
        }

        if ($to === TicketStatus::Resolved) {
            $this->timers->armAutoClose($ticket);

            $this->events->publish('ticket.resolved', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                ...$actor,
            ]);
        }

        if ($from === TicketStatus::Resolved && $to !== TicketStatus::Resolved) {
            $this->timers->cancelAutoClose($ticket);
            $ticket->refresh();
        }

        if ($to === TicketStatus::Closed) {
            $this->events->publish('ticket.closed', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                ...$actor,
            ]);
        }
    }

    /**
     * A-13's merge door: source → closed, outside the plain matrix, reachable only
     * from MergeTickets (FR-20 — "source → closed with merged_into=target").
     *
     * WHY NOT AN EDGE. §4.2's countersigned 15 edges reach `closed` only from
     * `resolved`, and AC-01's 64-cell test holds that line for every ORDINARY door.
     * A merge source can be any open-family status or `resolved`, so its close is an
     * operation door of the sole writer: same guarded repository write, same
     * ticket.status_changed + audit (SEC-03), same R-20 trigger stamps — and
     * deliberately NO ticket.closed and no lifecycle reopen-window: a superseded
     * ticket's only exit is unmerge (A-14).
     */
    public function closeForMerge(Ticket $source, TransitionContext $context): void
    {
        $from = $source->status();

        if (! $from->isOpenFamily() && $from !== TicketStatus::Resolved) {
            throw new \LogicException(sprintf(
                'closeForMerge on a %s ticket — A-13 admits open-family and resolved sources only; MergeTickets validates before calling.',
                $from->value,
            ));
        }

        DB::transaction(function () use ($source, $from, $context): void {
            // FR-10: a source leaving the open family releases its conversation's slot.
            if (! $source->linked_work && $from->isOpenFamily()) {
                $this->conversations->releaseOpenTicket($this->conversationFor($source), $source->identifier());
            }

            $written = $this->tickets->writeStatus($source, $from, TicketStatus::Closed);

            if (! $written) {
                // A racing transition moved the source mid-merge: the merge was
                // validated against a state that no longer exists, so the whole
                // operation re-evaluates rather than closing blind.
                $source->refresh();
                throw new IllegalTransition($source->status(), TicketStatus::Closed);
            }

            $source->refresh();
            $this->recordStatusChange($source, $from, TicketStatus::Closed, $context);

            // A resolved source's armed timer is meaningless once superseded (A-13).
            // The snapshot captured the deadline BEFORE this, so unmerge restores it.
            if ($from === TicketStatus::Resolved) {
                $this->timers->cancelAutoClose($source);
                $source->refresh();
            }
        });
    }

    /**
     * A-13's unmerge door: closed → the snapshot status, reachable only from
     * UnmergeTicket. Re-claims the FR-10 slot for open-family restores (refusing
     * open_ticket_exists on conflict), touches no reopened_count (an unmerge is not a
     * reopen), arms nothing (UnmergeTicket restores the ORIGINAL deadline verbatim —
     * arming fresh here would re-derive it from the current setting, which SS-03
     * forbids for an already-materialized timer).
     */
    public function reopenFromUnmerge(Ticket $source, TicketStatus $restored, TransitionContext $context): void
    {
        $from = $source->status();

        if ($from !== TicketStatus::Closed) {
            throw new \LogicException(sprintf(
                'reopenFromUnmerge on a %s ticket — a merged-away source is always closed (A-13); UnmergeTicket validates before calling.',
                $from->value,
            ));
        }

        if ($restored === TicketStatus::Closed) {
            // The snapshot said closed?! A merge of a closed source is refused at the
            // door (A-13), so a closed pre-merge status cannot have been snapshotted.
            throw new \LogicException('an unmerge snapshot cannot restore to closed — merge never admits a closed source (A-13).');
        }

        DB::transaction(function () use ($source, $from, $restored, $context): void {
            if (! $source->linked_work && $restored->isOpenFamily()) {
                $this->conversations->claimOpenTicket($this->conversationFor($source), $source->identifier());
            }

            $written = $this->tickets->writeStatus($source, $from, $restored);

            if (! $written) {
                $source->refresh();
                throw new IllegalTransition($source->status(), $restored);
            }

            $source->refresh();
            $this->recordStatusChange($source, $from, $restored, $context);
        });
    }

    /**
     * The event + audit pair every status write carries (FR-04/SEC-03), shared by the
     * plain transition and A-13's operation doors so no door can drift.
     */
    private function recordStatusChange(Ticket $ticket, TicketStatus $from, TicketStatus $to, TransitionContext $context): void
    {
        $this->events->publish('ticket.status_changed', [
            'tenant_id' => $ticket->getAttribute('tenant_id'),
            'ticket_id' => $ticket->identifier(),
            'conversation_id' => $ticket->conversation_id,
            'old_status' => $from->value,
            'new_status' => $to->value,
            'actor_type' => $context->actor->value,
            'actor_id' => $context->user?->identifier(),
        ]);

        $this->audit->record(
            actionKey: 'ticket.status_changed',
            entity: 'ticket',
            entityId: $ticket->identifier(),
            actorType: $context->actor->auditActor(),
            payload: [
                'ticket_id' => $ticket->identifier(),
                'old_status' => $from->value,
                'new_status' => $to->value,
                'reason' => $context->reason,
            ],
            actorId: $context->user?->identifier(),
        );
    }

    /**
     * FR-24's first-response signal for a reply that changes no status.
     *
     * The AI answering an already-open ticket is the ordinary case, and it must still
     * stamp first_response_at. The trigger validates the message (not a note, same
     * conversation, not pre-ticket) and stamps from the message's own database time.
     */
    public function recordPublicReply(Ticket $ticket, string $messageId, bool $isHuman): void
    {
        DB::transaction(function () use ($ticket, $messageId, $isHuman): void {
            $this->tickets->recordPublicReply($ticket, $messageId, $isHuman);
        });
    }

    /** @param array{permission: string, reason: bool, min_reason_length: int} $edge */
    private function assertPermitted(array $edge, TransitionContext $context): void
    {
        if (! $context->actor->requiresPermissionCheck()) {
            // FR-04's system/ai/automation actors: there is no user to check. These are
            // server-internal paths (the auto-close timer, the reopen consumer) that no
            // request can reach, and the state machine still governs them.
            return;
        }

        /** @var User $user */
        $user = $context->user;
        $verdict = $this->verdicts->verdictFor($user, $edge['permission']);

        if (! $verdict->allowed) {
            // Doc 03 EC-02 — "mid-action permission expiry naming the granter", the
            // remainder Doc 04 carried here because it needed a ticket ACTION to
            // interrupt (inherited row 7).
            //
            // The live verdict CANNOT supply the attribution, and that is the whole
            // subtlety: CapabilityMapBuilder drops an override whose `expires_at` has
            // passed BEFORE resolving (its query is `expires_at > now()`), so an
            // expired temporary allow leaves a plain role-level denial carrying no
            // granter and no expiry. Enforcement is right — the answer is deny — but
            // the person mid-action is told nothing, and a permission that vanishes
            // silently reads as a bug rather than as an expiry.
            //
            // So the refusal looks the attribution up from the override ROW, which is
            // still there (nothing hard-deletes at tenant scope — BR-02): the most
            // recently expired ALLOW for this user and key. Enforcement is untouched;
            // only the explanation improves.
            $expired = $this->expiredAllowFor($user, $edge['permission']);
            $grantedBy = null;
            $expiresAt = null;

            if ($expired instanceof UserPermissionOverride) {
                $grantedBy = $expired->granted_by;
                $expiresAt = $expired->expires_at?->toDateTimeImmutable();
            } elseif ($verdict->source === VerdictSource::OverrideDeny) {
                // A LIVE deny override: the granter is on the verdict itself, and
                // naming them is the same courtesy for the same reason.
                $grantedBy = $verdict->grantedBy;
                $expiresAt = $verdict->expiresAt;
            }

            throw new TransitionNotPermitted($edge['permission'], $grantedBy, $expiresAt);
        }
    }

    /**
     * The most recently expired ALLOW override for this user and key, if any — Doc 03
     * EC-02's attribution source. Live overrides are the resolver's business, never
     * this method's: an unexpired row here would mean the verdict had already answered.
     */
    private function expiredAllowFor(User $user, string $permission): ?UserPermissionOverride
    {
        /** @var UserPermissionOverride|null $override */
        $override = UserPermissionOverride::query()
            ->where('user_id', $user->identifier())
            ->where('permission', $permission)
            ->where('effect', 'allow')
            ->whereNotNull('expires_at')
            ->whereRaw('expires_at <= now()')
            ->orderByDesc('expires_at')
            ->first();

        return $override;
    }

    /** @param array{permission: string, reason: bool, min_reason_length: int} $edge */
    private function assertReason(array $edge, TransitionContext $context): void
    {
        if (! $edge['reason']) {
            return;
        }

        $reason = trim((string) $context->reason);

        if ($reason === '') {
            throw new TransitionRefused('reason_required', 'this transition records a mandatory reason (FR-04).');
        }

        // BR-06: escalation is "an exception path with mandatory articulation" — it is
        // reportable and coachable, which a two-character reason is not.
        if (mb_strlen($reason) < $edge['min_reason_length']) {
            throw new TransitionRefused('reason_too_short', sprintf(
                'the reason must be at least %d characters (FR-23).',
                $edge['min_reason_length'],
            ));
        }
    }

    private function assertInvariants(Ticket $ticket, TicketStatus $to, TransitionContext $context): void
    {
        // A-14: a merged-away source is FROZEN — "permanent banner" (FR-20) decides
        // it. No ordinary door may move it (a staff closed→open reopen would
        // un-supersede a ticket whose merged_into still says otherwise); the one way
        // back is UnmergeTicket, which clears the pointer before its door.
        if ($ticket->merged_into !== null) {
            throw new TransitionRefused(
                'merged_superseded',
                sprintf('this ticket was merged into %s and is frozen; unmerge it within the window, or work the surviving ticket (FR-20/A-14).', $ticket->merged_into),
            );
        }

        // BR-07: channel_unreachable "never auto-resolves or auto-closes a ticket …
        // humans decide outcomes for unreachable customers". So the bar is on the
        // AUTOMATED actors only — a staff member may still resolve deliberately.
        if (
            $ticket->channel_unreachable
            && ! $context->actor->requiresPermissionCheck()
            && in_array($to, [TicketStatus::Resolved, TicketStatus::Closed], true)
        ) {
            throw new TransitionRefused(
                'channel_unreachable_hold',
                'the customer is unreachable on their channel; a human decides this outcome (BR-07).',
            );
        }
    }

    private function conversationFor(Ticket $ticket): Conversation
    {
        /** @var Conversation $conversation */
        $conversation = Conversation::query()->findOrFail($ticket->conversation_id);

        return $conversation;
    }
}
