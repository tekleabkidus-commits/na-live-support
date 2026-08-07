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
 *   Team -> department_id   PLACEHOLDER, and deliberately the same column as Dept:
 *   Dept -> department_id   teams are Doc 11's structure and no team column exists yet.
 *
 * TEAM/DEPT STILL FAIL CLOSED, and that is not an oversight. `MembershipSource` binds
 * to `EmptyMembership` in production until Doc 11 supplies real membership, so
 * ScopeSets resolves both levels to the EMPTY set — a Team-scoped role sees nothing
 * rather than everything. Doc 06 changes only the `Own` level from "structurally
 * impossible" to "real", which is the narrow, provable step; widening Team/Dept before
 * Doc 11 exists would be the open-fall Doc 03's NFR-04 sabotage was written to catch.
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
     * WHY THIS IS NOT THE SAME QUESTION AS visible(), and why using visible() here was
     * a real defect rather than a stricter reading. An `Own`-scoped role sees tickets
     * "assigned to the user" — so an UNASSIGNED ticket sitting in a queue is, by
     * definition, outside every Own-scoped agent's scope. Checking visible() before
     * assigning therefore refused every claim by every Own-scoped agent, which is most
     * agents, and made `tickets.assign_self` unusable. The check was circular: you may
     * only take work you already have.
     *
     * FR-15's actual requirement is that the assignee "must hold view scope over the
     * ticket" — a statement about the state AFTER assignment, which is the only state
     * in which the assignee has to be able to open it. So:
     *
     *   Own   -> always satisfied. The assignment is what makes it theirs; there is no
     *            ticket an Own-scoped agent could be assigned and then not see.
     *   Team  -> the ticket's team/department must be in their membership. Assignment
     *   Dept     does NOT change the ticket's department, so this is a real constraint
     *            and it is where FR-15 actually bites: handing a Payments ticket to a
     *            Withdrawals-only agent leaves them unable to open it.
     *   All   -> always satisfied.
     *   none  -> refused (a role with no ticket scope holds no tickets).
     *
     * Pre-Doc 11 this means Team/Dept assignment is refused, because EmptyMembership
     * gives those levels an empty set — fail-closed, consistent with everywhere else,
     * and it loosens the moment Doc 11 supplies real membership.
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
