<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Merge;

use App\Domain\Tickets\Followers\TicketFollowers;
use App\Domain\Tickets\Storage\TicketScope;
use App\Domain\Tickets\Tags\TicketTags;
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
use Illuminate\Support\Str;

/**
 * FR-20's merge: source(s) → target, one transaction, links move and message rows
 * never do (BR-02 — the "link rewrite" is an UPDATE of ticket_message_anchors.ticket_id,
 * exactly as that table's migration promises).
 *
 * THE HARD RULES, in refusal order:
 *   SEC-06  cross-customer merge is refused server-side, always — a Doc 08 customer
 *           merge must happen first. This is privacy physics, not workflow.
 *   SEC-01  the actor's scope is re-checked on the TARGET (`target_out_of_scope`):
 *           moving work cannot smuggle visibility.
 *   A-13    sources are open-family or `resolved` only (a `closed` ticket is immutable
 *           except as a merge TARGET — FR-07; `spam` is metrics-excluded traffic and
 *           stays out — N-14); targets are any non-spam ticket; cycles are refused
 *           because EC-03's transitive resolution requires an acyclic chain.
 *
 * THE SNAPSHOT COMES FIRST (T-04/AC-04): before any mutation, each link writes its
 * pre-merge state — source {status, pending_reason, assignee_id, auto_close_at,
 * merged_into}, target {follower set, tag set}, the anchor rows about to move — to
 * ticket_merge_snapshots (insert-only by grant, SEC-02). Skipping it is sabotage #3's
 * scenario and AC-04's unmerge goes red without it. snapshotShape() is public so the
 * AC-04 test recomputes the same extraction after unmerge and asserts diff = ∅.
 *
 * WHAT MERGE DOES NOT DO. It does not move messages (BR-02). It does not emit
 * ticket.closed for the source (A-13: no reopen window on a superseded ticket — the
 * banner is permanent until unmerge, A-14). It does not touch SLA instances: "target
 * SLA governs (source instances closed superseded)" is Doc 12's consumption of
 * ticket.merged — the seam is the event, the instances do not exist yet. Source
 * form_data stays on the source row; §8's artifact card renders it from there
 * (Doc 07), because attaching a copy would be the data movement BR-02 forbids.
 *
 * MULTI-SOURCE ORDERING HAZARD, documented for the owner: each link's snapshot
 * captures the target's follower/tag sets AS OF THAT LINK's turn, so unmerging links
 * out of reverse order restores the target to an intermediate state. EC-03 scopes
 * unmerge "per-link within its own 24 h" and says nothing about ordering; recorded in
 * BUILD-STATUS risks rather than silently reordered.
 */
final class MergeTickets
{
    public function __construct(
        private readonly TicketScope $scope,
        private readonly TicketTransitionService $transitions,
        private readonly TicketFollowers $followers,
        private readonly TicketTags $tags,
        private readonly MergeChain $chain,
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<Ticket>  $sources
     */
    public function merge(array $sources, Ticket $target, User $actor): Ticket
    {
        if ($sources === []) {
            throw new DomainException('a merge with no sources merges nothing (FR-20).');
        }

        if (! $this->verdicts->verdictFor($actor, 'tickets.merge')->allowed) {
            throw new DomainException('tickets.merge is required (FR-20).');
        }

        // SEC-01: the re-check is on the TARGET object, right now, for this actor —
        // permission AND scope, the AssignTicket pair (scope level and the view key
        // are separate facts; a role can hold one without the other).
        if (! $this->verdicts->verdictFor($actor, 'tickets.view')->allowed
            || ! $this->scope->visible($actor, $target->identifier())) {
            throw new TransitionRefused(
                'target_out_of_scope',
                'the merge target is outside your view scope (SEC-01) — moving work cannot smuggle visibility.',
            );
        }

        if ($target->status() === TicketStatus::Spam) {
            throw new TransitionRefused('merge_target_spam', 'a spam ticket cannot receive a merge (A-13; N-14 keeps excluded traffic out of the metrics).');
        }

        $targetChain = $this->chain->chainIds($target);

        foreach ($sources as $source) {
            $this->assertMergeable($source, $target, $targetChain);
        }

        return DB::transaction(function () use ($sources, $target, $actor): Ticket {
            foreach ($sources as $source) {
                $this->mergeOne($source, $target, $actor);
            }

            $target->refresh();

            return $target;
        });
    }

    /**
     * The pre-merge state of one link, in the exact shape the snapshot stores — public
     * because AC-04's proof is this function applied twice: at merge time (stored) and
     * after unmerge (recomputed), asserting the diff is empty.
     *
     * @return array{source: array{state: string, pending_reason: string|null, assignee_id: string|null, auto_close_at: string|null, merged_into: string|null}, target: array{follower_ids: list<string>, tags: list<string>}, anchor_ids: list<string>}
     */
    public function snapshotShape(Ticket $source, Ticket $target): array
    {
        /** @var list<string> $anchorIds */
        $anchorIds = DB::table('ticket_message_anchors')
            ->where('ticket_id', $source->identifier())
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $followerIds = $this->followers->followerIds($target);
        sort($followerIds);

        $targetTags = $this->tags->tagsOf($target);
        sort($targetTags);

        return [
            'source' => [
                // The key is `state`, not `status`, deliberately: NFR-03's two guards
                // treat any quoted status key assigned in a Ticket-context file as a
                // status WRITE (their coarseness is their strength — and the grep half
                // reads comments too), and this array is a snapshot, not a write.
                'state' => $source->status()->value,
                'pending_reason' => $source->pending_reason,
                'assignee_id' => $source->assignee_id,
                // Microsecond-exact ISO, UTC — the byte-identical bar covers the armed
                // timer (A-13), and this is the format unmerge writes back verbatim.
                'auto_close_at' => $source->auto_close_at?->format('Y-m-d H:i:s.uP'),
                'merged_into' => $source->merged_into,
            ],
            'target' => [
                'follower_ids' => $followerIds,
                'tags' => $targetTags,
            ],
            'anchor_ids' => $anchorIds,
        ];
    }

    /** @param list<string> $targetChain */
    private function assertMergeable(Ticket $source, Ticket $target, array $targetChain): void
    {
        // SEC-06 first: the cross-customer refusal must not depend on any softer check
        // passing or failing — it is the hard rule the others sit under.
        if ($source->customer_id !== $target->customer_id) {
            throw new TransitionRefused(
                'cross_customer_merge',
                'the tickets belong to different customers; merge the customers first (Doc 08), then the tickets (SEC-06).',
            );
        }

        if (in_array($source->identifier(), $targetChain, true)) {
            throw new TransitionRefused('merge_cycle', 'this merge would make the merged_into chain circular — EC-03\'s transitive resolution requires it stays a chain (A-13).');
        }

        if ($source->merged_into !== null) {
            throw new TransitionRefused('merged_superseded', 'this ticket is already merged away; unmerge it first (A-14).');
        }

        $status = $source->status();

        if ($status === TicketStatus::Closed) {
            throw new TransitionRefused('merge_source_closed', 'a closed ticket is immutable except as a merge TARGET (FR-07/A-13) — merge into it, not from it.');
        }

        if ($status === TicketStatus::Spam) {
            throw new TransitionRefused('merge_source_spam', 'spam is excluded traffic (N-14) and cannot be merged into a live ticket (A-13).');
        }
    }

    private function mergeOne(Ticket $source, Ticket $target, User $actor): void
    {
        // THE SNAPSHOT, BEFORE ANY MUTATION (T-04). Everything below this insert is
        // reversible because of it; without it AC-04's unmerge has nothing to restore.
        $shape = $this->snapshotShape($source, $target);

        DB::table('ticket_merge_snapshots')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $source->getAttribute('tenant_id'),
            'source_ticket_id' => $source->identifier(),
            'target_ticket_id' => $target->identifier(),
            'snapshot' => json_encode($shape, JSON_THROW_ON_ERROR),
        ]);

        // BR-02's link rewrite: anchors move, message rows never do.
        DB::table('ticket_message_anchors')
            ->where('ticket_id', $source->identifier())
            ->update(['ticket_id' => $target->identifier(), 'updated_at' => DB::raw('clock_timestamp()')]);

        // Followers union (FR-20). autoFollow: the platform is keeping people attached
        // to work that was already theirs — same rationale as FR-23's auto-follow, and
        // the standard follower_added events ride for Doc 16.
        foreach ($this->followers->followerIds($source) as $followerId) {
            $follower = User::query()->find($followerId);

            if ($follower instanceof User) {
                $this->followers->autoFollow($target, $follower);
            }
        }

        // Tags union (FR-20), in the database's own set arithmetic.
        $sourceTags = $this->tags->tagsOf($source);

        if ($sourceTags !== []) {
            $this->tags->unionForMerge($target, $sourceTags, $actor);
        }

        // The A-13 operation door: source → closed through the sole writer, with the
        // FR-10 slot bookkeeping and a resolved source's timer cancelled — and
        // deliberately no ticket.closed (no reopen window on a superseded ticket).
        $this->transitions->closeForMerge($source, TransitionContext::staff($actor));

        Ticket::query()->whereKey($source->identifier())->update([
            'merged_into' => $target->identifier(),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $source->refresh();

        // FR-20 "events on both": one event per link, carrying both sides, so Doc 07
        // renders it on either timeline and Doc 12 supersedes the source's instances.
        $this->events->publish('ticket.merged', [
            'tenant_id' => $source->getAttribute('tenant_id'),
            'source_ticket_id' => $source->identifier(),
            'target_ticket_id' => $target->identifier(),
            'source_conversation_id' => $source->conversation_id,
            'target_conversation_id' => $target->conversation_id,
            'actor_type' => 'staff',
            'actor_id' => $actor->identifier(),
        ]);

        $this->audit->record(
            actionKey: 'ticket.merged',
            entity: 'ticket',
            entityId: $source->identifier(),
            actorType: AuditActor::Staff,
            payload: [
                'source_ticket_id' => $source->identifier(),
                'target_ticket_id' => $target->identifier(),
            ],
            actorId: $actor->identifier(),
        );
    }
}
