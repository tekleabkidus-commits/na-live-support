<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Storage;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\Scopes\ScopeColumns;
use App\Support\Rbac\Scopes\ScopeSets;

/**
 * Doc 03's scope machinery, wired to the ticket's real columns — the thing Doc 04 had
 * to fail closed without.
 *
 * `ScopeColumns`' own docblock named this: "Each consuming model declares its own (Doc
 * 04's conversations, Doc 06's tickets, Doc 08's customers) — the CONSTRAINT logic
 * stays in ScopeSets, once (R-03)." So this class declares the mapping and nothing
 * else; every decision is still ScopeSets'.
 *
 * THE MAPPING, and why each column:
 *   Own  -> assignee_id     "assigned to the user" is what owning a ticket means here.
 *                           FR-15 makes assignment the thing that must intersect with
 *                           view scope, so the same column answers both questions.
 *   Team -> department_id   BY DESIGN (Doc 11 A-50 point 3, S11 step 3): a ticket has no
 *   Dept -> department_id   team; "records of the user's teams" are the tickets in the
 *                           departments the team's queues serve, so the team column IS
 *                           department_id and its id source is the team's queue REACH
 *                           (MembershipSource::teamReachDepartmentIdsFor, declared by
 *                           teamColumnHolds: 'department').
 *
 * TEAM/DEPT RESOLVE TO REAL ROWS since S11 step 3 (Packet 3, countersigned): `MembershipSource`
 * binds to Doc 11's `OrgMembership` in production. The transition — fail-closed empty before,
 * exactly the expected sets after, a foreign or expired row anywhere = red — is
 * MembershipTransitionTest; `EmptyMembership` remains the test-only fail-closed control.
 */
final class TicketScope
{
    public function __construct(
        private readonly ScopeSets $scopes,
        private readonly CapabilityMapProvider $maps,
    ) {}

    public static function columns(): ScopeColumns
    {
        return new ScopeColumns(
            owner: 'assignee_id',
            team: 'department_id',
            department: 'department_id',
            teamColumnHolds: 'department',   // Doc 11 A-50 point 3 (S11 step 3): the team's queue reach
        );
    }

    /** Is this ticket inside the user's view scope RIGHT NOW? (SEC-01's re-check.) */
    public function visible(User $user, string $ticketId): bool
    {
        return $this->scopes->objectVisible(
            $this->ticketQuery(),
            $ticketId,
            'tickets',
            $user,
            self::columns(),
        );
    }

    /**
     * ScopeSets::objectVisible() is declared over `Builder<Model>`, and a
     * `Builder<Ticket>` is NOT a subtype of it under PHPStan's generics. Narrowing
     * happens here, in the one consumer, rather than by widening Doc 03's signature
     * for Doc 06's convenience.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function ticketQuery(): \Illuminate\Database\Eloquent\Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = Ticket::query();

        return $query;
    }

    /**
     * FR-15's pre-check: would this user hold view scope over the ticket ONCE ASSIGNED
     * to it?
     *
     * THE READING IS FILED AS INTERPRETATION A-11 in docs/ANSWERS-FROM-DOC-AUTHOR.md,
     * which is canonical. In short: the present-tense question is circular (an
     * unassigned ticket is outside every Own scope, so nobody could ever claim one) and
     * contradicts FR-16's claim race, which presupposes claiming unheld work. The
     * constraint still bites on Team/Dept, where the ticket's department must be in the
     * assignee's membership — and pre-Doc 11 that is the empty set, so those levels
     * fail closed.
     *
     *   Own   -> satisfied by the assignment itself
     *   Team  -> the ticket's department must be in their membership
     *   Dept  -> same
     *   All   -> always satisfied
     *   none  -> refused
     */
    public function visibleOnceAssignedTo(User $user, Ticket $ticket): bool
    {
        // Read from the SAME source ScopeSets reads, rather than adding a level
        // accessor to Doc 03's API for one caller: the two can then never disagree
        // about what level this user holds.
        $level = $this->maps->mapFor($user)->scopeFor('tickets');

        return match ($level) {
            'All' => true,
            // The assignment itself satisfies Own — see the docblock above.
            'Own' => true,
            'Team', 'Dept' => $this->scopes->objectVisible(
                $this->ticketQuery(),
                $ticket->identifier(),
                'tickets',
                $user,
                self::columns(),
            ),
            default => false,
        };
    }
}
