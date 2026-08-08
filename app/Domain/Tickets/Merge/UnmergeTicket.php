<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Merge;

use App\Domain\Tickets\Followers\TicketFollowers;
use App\Domain\Tickets\Tags\TicketTags;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-20's 24 h reversal: "unmerge (restores prior state from snapshot; audited)" —
 * AC-04's bar is byte-identical (snapshot diff = ∅ over everything the merge touched).
 *
 * THE WINDOW IS THE SNAPSHOT'S OWN created_at + 24 h, per link (EC-03), evaluated in
 * SQL against clock_timestamp() — and FS-04 makes PRESENCE the authority in the prune
 * race: a pruned row refuses identically to a stale one, the refusal states the window
 * passed, and BOTH outcomes are audited (the refusal under its own key, because an
 * audit trail that only records successes is half a trail).
 *
 * RESTORE, THEN RECONCILE (A-13): the snapshot restore is verbatim — status through
 * the sole writer's unmerge door, pending_reason, assignee, the armed auto_close_at
 * (column AND candidate), merged_into cleared, anchors moved back, target
 * follower/tag sets reset. Then, separately evented, the EC-06 rule: a restored
 * assignee who was deactivated while the source sat merged (EC-06's hand-back sweeps
 * open-family only, so it cannot have caught a merged-closed ticket) is handed back
 * exactly as ReopenTicket::reconcileAssignee does it.
 *
 * THE OPEN-SLOT CONFLICT: restoring an open-family status re-claims the conversation's
 * FR-10 slot; if the conversation grew a new open-family ticket during the 24 h, the
 * claim refuses with the existing `open_ticket_exists` and the whole unmerge rolls
 * back — the operator resolves the newer ticket first (A-13).
 */
final class UnmergeTicket
{
    public function __construct(
        private readonly TicketTransitionService $transitions,
        private readonly TicketFollowers $followers,
        private readonly TicketTags $tags,
        private readonly TicketTimers $timers,
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function unmerge(Ticket $source, User $actor): Ticket
    {
        // A-13: the reversal is governed by the same key as the operation — the
        // escalate/de-escalate precedent; the catalog is closed.
        if (! $this->verdicts->verdictFor($actor, 'tickets.merge')->allowed) {
            throw new DomainException('tickets.merge is required (FR-20 — unmerge is the same operation in reverse).');
        }

        $targetId = $source->merged_into;

        if ($targetId === null) {
            throw new TransitionRefused('not_merged', 'this ticket is not merged into anything — there is nothing to unmerge (FR-20).');
        }

        // FS-04: snapshot presence is the authority, and the window is the row's own
        // DB-stamped created_at + 24 h. One SQL question answers both.
        /** @var object{id: string, snapshot: string}|null $row */
        $row = DB::selectOne(
            "SELECT id, snapshot FROM ticket_merge_snapshots
              WHERE source_ticket_id = ? AND target_ticket_id = ?
                AND created_at + interval '24 hours' >= clock_timestamp()
              ORDER BY created_at DESC
              LIMIT 1",
            [$source->identifier(), $targetId],
        );

        if ($row === null) {
            // FS-04: "both attempts audited" — the refused attempt is a fact about
            // this ticket's history too, not just the successful one.
            $this->audit->record(
                actionKey: 'ticket.unmerge_refused',
                entity: 'ticket',
                entityId: $source->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'source_ticket_id' => $source->identifier(),
                    'target_ticket_id' => $targetId,
                    'reason' => 'window_passed',
                ],
                actorId: $actor->identifier(),
            );

            throw new TransitionRefused('unmerge_window_passed', 'the 24 h unmerge window for this merge has passed (FR-20/FS-04); the merge is now permanent.');
        }

        /** @var array{source: array{state: string, pending_reason: string|null, assignee_id: string|null, auto_close_at: string|null, merged_into: string|null}, target: array{follower_ids: list<string>, tags: list<string>}, anchor_ids: list<string>} $shape */
        $shape = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);

        /** @var Ticket $target */
        $target = Ticket::query()->findOrFail($targetId);

        return DB::transaction(function () use ($source, $target, $actor, $shape): Ticket {
            // BR-02's link rewrite, reversed: exactly the rows the merge moved.
            if ($shape['anchor_ids'] !== []) {
                DB::table('ticket_message_anchors')
                    ->whereIn('id', $shape['anchor_ids'])
                    ->where('ticket_id', $target->identifier())
                    ->update(['ticket_id' => $source->identifier(), 'updated_at' => DB::raw('clock_timestamp()')]);
            }

            // Target sets back to their snapshot state, standard events riding.
            $this->followers->resetForUnmerge($target, $shape['target']['follower_ids']);
            $this->tags->resetForUnmerge($target, $shape['target']['tags']);

            // The source row, verbatim: pointer cleared and pre-merge fields restored
            // BEFORE the status door, so the door writes onto a source that is no
            // longer superseded.
            Ticket::query()->whereKey($source->identifier())->update([
                'merged_into' => $shape['source']['merged_into'],
                'pending_reason' => $shape['source']['pending_reason'],
                'assignee_id' => $shape['source']['assignee_id'],
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $source->refresh();

            // Status through the sole writer's unmerge door (A-13): closed → the
            // snapshot status, FR-10 slot re-claimed for open-family (refuses
            // open_ticket_exists on conflict), no reopened_count — an unmerge is not
            // a reopen.
            $this->transitions->reopenFromUnmerge(
                $source,
                TicketStatus::from($shape['source']['state']),
                TransitionContext::staff($actor),
            );
            $source->refresh();

            // The armed timer, byte-identical (A-13): the exact pre-merge deadline
            // back on the column and the candidate list — never re-derived from the
            // current setting (SS-03's arm-time materialization survives the round trip).
            if ($shape['source']['auto_close_at'] !== null) {
                DB::update(
                    'UPDATE tickets SET auto_close_at = ?::timestamptz, updated_at = clock_timestamp() WHERE id = ?',
                    [$shape['source']['auto_close_at'], $source->identifier()],
                );
                $source->refresh();

                $deadline = $source->auto_close_at;
                if ($deadline !== null) {
                    $this->timers->add($this->timers->member($source), (int) $deadline->format('Uu'));
                }
            }

            $this->events->publish('ticket.unmerged', [
                'tenant_id' => $source->getAttribute('tenant_id'),
                'source_ticket_id' => $source->identifier(),
                'target_ticket_id' => $target->identifier(),
                'restored_status' => $shape['source']['state'],
                'actor_type' => 'staff',
                'actor_id' => $actor->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.unmerged',
                entity: 'ticket',
                entityId: $source->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'source_ticket_id' => $source->identifier(),
                    'target_ticket_id' => $target->identifier(),
                    'restored_status' => $shape['source']['state'],
                ],
                actorId: $actor->identifier(),
            );

            // A-13's reconcile, AFTER the verbatim restore: EC-06's rule is general,
            // and a deactivated assignee must not silently keep reopened work.
            $this->reconcileAssignee($source);

            return $source;
        });
    }

    /**
     * EC-06's hand-back, the ReopenTicket::reconcileAssignee shape — deliberately the
     * same event and audit key so Doc 11 re-routes an unmerge hand-back exactly like a
     * reopen hand-back.
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
