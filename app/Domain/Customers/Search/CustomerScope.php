<?php

declare(strict_types=1);

namespace App\Domain\Customers\Search;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\Scopes\MembershipSource;
use Illuminate\Database\Query\Builder;

/**
 * The ONE BRAIN for what "a customer in my scope" means (FR-20's
 * scope-correct search; AC-07's Doc 03 AC-03 reuse; SEC-05's boundary).
 *
 * A customer has no assignee or department of their own — the ONLY
 * assignment/department structure they touch is their TICKETS, so the
 * customers-module scope levels (Doc 03 Appendix B: All/Dept/Team/Own per
 * template) derive from ticket relationships:
 *
 *   Own   → the customer has ≥1 ticket assigned to the viewer
 *   Team/Dept → ≥1 ticket in the viewer's member departments (EMPTY until
 *               Doc 11 supplies membership — fail-closed, the T-03 seam)
 *   All   → no constraint (tenant isolation is layer 1 / RLS, not this)
 *   none  → the EMPTY set (NFR-04's posture: silence fails closed)
 *
 * Both backends read THIS derivation — the Meilisearch filter (over the
 * denormalized assignee_ids/department_ids doc arrays) and the DB constraint
 * (EXISTS over the same ticket columns, FS-01's degrade path) — so scoped
 * search and its degrade cannot diverge (inherited row 6's law: one set of
 * live checks, never a second engine).
 *
 * The level reads scopeFor('customers') — NOT the tickets signature — because
 * roles are editable and the two modules may diverge (the templates ship them
 * equal; the law is per-module).
 */
final class CustomerScope
{
    public function __construct(
        private readonly CapabilityMapProvider $maps,
        private readonly MembershipSource $membership,
    ) {}

    /** The ViewHash signature grammar, module = customers. */
    public function signature(User $viewer): string
    {
        $level = $this->maps->mapFor($viewer)->scopeFor('customers');

        return match ($level) {
            'All' => 'all',
            'Own' => 'own:'.$viewer->identifier(),
            // Doc 11 A-50 point 3 (S11 step 3): team:/dept: filter department_ids — reach ids here.
            'Team' => 'team:'.implode(',', $this->sorted($this->membership->teamReachDepartmentIdsFor($viewer))),
            'Dept' => 'dept:'.implode(',', $this->sorted($this->membership->departmentIdsFor($viewer))),
            default => 'none',
        };
    }

    /** Meilisearch filter for a signature. Callers handle 'none' BEFORE querying (empty set, no round-trip). */
    public function meiliFilter(string $signature): ?string
    {
        if ($signature === 'all') {
            return null;
        }
        if (str_starts_with($signature, 'own:')) {
            return sprintf("assignee_ids = '%s'", substr($signature, 4));
        }

        // team:/dept: — department membership (server-resolved uuids).
        $ids = array_filter(explode(',', substr($signature, 5)));
        if ($ids === []) {
            return "department_ids = 'none-matches-nothing'"; // fail-closed empty set
        }

        return sprintf('department_ids IN [%s]', implode(', ', array_map(
            static fn (string $id): string => sprintf("'%s'", $id),
            $ids,
        )));
    }

    /**
     * The SAME semantics on the customers table (the FS-01 degrade and any
     * DB-side directory listing): EXISTS over the ticket columns the index
     * arrays are copied from.
     */
    public function constrainQuery(Builder $query, string $signature): Builder
    {
        if ($signature === 'all') {
            return $query;
        }
        if ($signature === 'none') {
            return $query->whereRaw('1 = 0');
        }

        if (str_starts_with($signature, 'own:')) {
            $viewerId = substr($signature, 4);

            return $query->whereExists(function (Builder $sub) use ($viewerId): void {
                $sub->from('tickets')
                    ->whereColumn('tickets.customer_id', 'customers.id')
                    ->where('tickets.assignee_id', $viewerId);
            });
        }

        $ids = array_filter(explode(',', substr($signature, 5)));
        if ($ids === []) {
            return $query->whereRaw('1 = 0'); // fail-closed empty membership
        }

        return $query->whereExists(function (Builder $sub) use ($ids): void {
            $sub->from('tickets')
                ->whereColumn('tickets.customer_id', 'customers.id')
                ->whereIn('tickets.department_id', $ids);
        });
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
