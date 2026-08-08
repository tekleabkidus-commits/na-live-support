<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Followers;

use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Storage\TicketScope;
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
 * FR-18: "Followers: staff with view scope can follow/unfollow themselves
 * (tickets.follow); assigners can add followers; followers receive notifications
 * (status, public reply, note-mention, escalation) with per-user preferences (Doc 16)."
 *
 * TWO DOORS, TWO KEYS. Following yourself is `tickets.follow`; adding someone ELSE is
 * an assigner's act and takes `tickets.assign` (filed mapping A-9 — the catalog is
 * closed at 111 keys and no follow-others key exists, so the document's own word
 * "assigners" picks the key rather than an invention).
 *
 * VIEW SCOPE IS REQUIRED IN BOTH DIRECTIONS, for the reason FR-15 gives about
 * assignment: a follower who cannot see the ticket receives notifications about work
 * they cannot open. Same refusal code, because it is the same mistake.
 *
 * THE NOTIFICATIONS ARE NOT HERE. FR-18's delivery and its per-user preferences are
 * Doc 16's; what this owns is the membership set those consumers read, and the
 * follower_added/removed events that tell them it changed.
 */
final class TicketFollowers
{
    public function __construct(
        private readonly TicketScope $scope,
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function follow(Ticket $ticket, User $self): bool
    {
        if (! $this->verdicts->verdictFor($self, 'tickets.follow')->allowed) {
            throw new DomainException('tickets.follow is required (FR-18).');
        }

        return $this->add($ticket, $self, addedBy: null);
    }

    public function addFollower(Ticket $ticket, User $follower, User $actor): bool
    {
        if (! $this->verdicts->verdictFor($actor, 'tickets.assign')->allowed) {
            throw new DomainException('adding another follower is an assigner\'s act — tickets.assign is required (FR-18, mapping A-9).');
        }

        return $this->add($ticket, $follower, addedBy: $actor);
    }

    /**
     * FR-23's auto-follow: "the original assignee auto-follows" on escalation. No
     * permission check — the platform is keeping someone attached to work that was
     * already theirs, not granting anyone new visibility.
     */
    public function autoFollow(Ticket $ticket, User $follower): bool
    {
        return $this->insert($ticket, $follower, addedBy: null, actorForAudit: null);
    }

    public function unfollow(Ticket $ticket, User $self): bool
    {
        $removed = DB::table('ticket_followers')
            ->where('ticket_id', $ticket->identifier())
            ->where('user_id', $self->identifier())
            ->delete();

        if ($removed === 0) {
            return false;
        }

        $this->events->publish('ticket.follower_removed', [
            'tenant_id' => $ticket->getAttribute('tenant_id'),
            'ticket_id' => $ticket->identifier(),
            'user_id' => $self->identifier(),
            'by' => $self->identifier(),
        ]);

        $this->audit->record(
            actionKey: 'ticket.follower_removed',
            entity: 'ticket',
            entityId: $ticket->identifier(),
            actorType: AuditActor::Staff,
            payload: ['ticket_id' => $ticket->identifier(), 'user_id' => $self->identifier()],
            actorId: $self->identifier(),
        );

        return true;
    }

    /**
     * AC-04's restore: reset the follower set to exactly the snapshot's ids
     * (FR-20/A-13 — the unmerge writes the pre-merge set back verbatim). Removals emit
     * the standard follower_removed events so Doc 16 stops notifying people the merge
     * attached. No permission check: UnmergeTicket's tickets.merge governs, and no
     * scope re-check either — everyone in the kept set already passed it when they
     * first followed.
     *
     * @param  list<string>  $keepUserIds
     */
    public function resetForUnmerge(Ticket $ticket, array $keepUserIds): void
    {
        $current = $this->followerIds($ticket);
        $remove = array_values(array_diff($current, $keepUserIds));

        if ($remove === []) {
            return;
        }

        DB::table('ticket_followers')
            ->where('ticket_id', $ticket->identifier())
            ->whereIn('user_id', $remove)
            ->delete();

        foreach ($remove as $userId) {
            $this->events->publish('ticket.follower_removed', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'user_id' => $userId,
                'by' => null,
            ]);

            $this->audit->record(
                actionKey: 'ticket.follower_removed',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::System,
                payload: ['ticket_id' => $ticket->identifier(), 'user_id' => $userId, 'reason' => 'unmerge_restore'],
            );
        }
    }

    /** @return list<string> the user ids Doc 16's notification fan-out reads */
    public function followerIds(Ticket $ticket): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('ticket_followers')
            ->where('ticket_id', $ticket->identifier())
            ->orderBy('created_at')
            ->pluck('user_id')
            ->all();

        return $ids;
    }

    private function add(Ticket $ticket, User $follower, ?User $addedBy): bool
    {
        // Same as-if question as assignment: a follower is being ATTACHED to the
        // ticket, so the test is whether they could open it as a follower — not
        // whether they already hold it (they usually do not, which is why they are
        // being added).
        if (! $this->verdicts->verdictFor($follower, 'tickets.view')->allowed
            || ! $this->scope->visibleOnceAssignedTo($follower, $ticket)) {
            throw new AssigneeOutOfScope($follower->identifier());
        }

        return $this->insert($ticket, $follower, $addedBy, $addedBy ?? $follower);
    }

    private function insert(Ticket $ticket, User $follower, ?User $addedBy, ?User $actorForAudit): bool
    {
        // Following twice is not an error — the unique index makes the set a set, and
        // a second follow is simply already true.
        $existing = DB::table('ticket_followers')
            ->where('ticket_id', $ticket->identifier())
            ->where('user_id', $follower->identifier())
            ->exists();

        if ($existing) {
            return false;
        }

        return DB::transaction(function () use ($ticket, $follower, $addedBy, $actorForAudit): bool {
            DB::table('ticket_followers')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'user_id' => $follower->identifier(),
                'added_by' => $addedBy?->identifier(),
            ]);

            $this->events->publish('ticket.follower_added', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'user_id' => $follower->identifier(),
                'by' => $actorForAudit?->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.follower_added',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: $actorForAudit instanceof User ? AuditActor::Staff : AuditActor::System,
                payload: ['ticket_id' => $ticket->identifier(), 'user_id' => $follower->identifier()],
                actorId: $actorForAudit?->identifier(),
            );

            return true;
        });
    }
}
