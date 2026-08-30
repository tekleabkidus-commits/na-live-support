<?php

declare(strict_types=1);

namespace App\Support\Rbac\Scopes;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * scopeSet(module, user) — THE one implementation of Doc 03's data scopes
 * (FR-03, T-03, NFR-04; R-03: "single named-scope implementation per module"
 * is why this logic exists exactly once and modules pass their column map in).
 *
 * Every path that is not an explicit, positive grant of visibility constrains
 * the query to the EMPTY SET (`WHERE 1 = 0`):
 *
 *  - the role's scope map is silent about the module → empty (NFR-04: a
 *    missing filter fails closed, never open — Doc 01 RLS stays the backstop,
 *    not the mechanism);
 *  - scope=Team but the user has no team → empty-team set (EC-04; the role
 *    editor warns at save time, this layer just refuses);
 *  - scope=Dept with no department → same;
 *  - an unknown scope level (bad data) → empty.
 *
 * `All` applies no constraint — tenant isolation is already Doc 01's RLS +
 * global scopes, not this layer's job.
 *
 * EC-06 note for consumers: a scoped VERB checked with no object (list
 * endpoints) is verdict-allow at the map layer and THIS filter is what scopes
 * the rows — documented here so nobody double-checks per row.
 */
final class ScopeSets
{
    public function __construct(
        private readonly CapabilityMapProvider $maps,
        private readonly MembershipSource $membership,
    ) {}

    /**
     * Constrain $query to the rows the user's role scope makes visible for
     * $module. Composable: callers chain their own wheres freely around it
     * (T-03: named Eloquent scopes on the consuming models call this).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $module, User $user, ScopeColumns $columns): Builder
    {
        $level = $this->maps->mapFor($user)->scopeFor($module);

        return match ($level) {
            'Own' => $query->where($columns->owner, $user->identifier()),
            // Doc 11 A-50 point 3 (S11 step 3): the id KIND follows the column's declaration.
            'Team' => $this->membershipConstraint($query, $columns->team, $columns->teamColumnHolds === 'department'
                ? $this->membership->teamReachDepartmentIdsFor($user)
                : $this->membership->teamIdsFor($user)),
            'Dept' => $this->membershipConstraint($query, $columns->department, $this->membership->departmentIdsFor($user)),
            'All' => $query,
            default => $this->emptySet($query),
        };
    }

    /**
     * FR-05's object half: object ∈ scopeSet? Reuses apply() on a
     * single-key query so the object check and the list filter can NEVER
     * diverge (R-03's whole worry).
     *
     * @param  Builder<Model>  $query  a fresh query for the object's model
     */
    public function objectVisible(Builder $query, string $objectKey, string $module, User $user, ScopeColumns $columns): bool
    {
        return $this->apply($query->whereKey($objectKey), $module, $user, $columns)->exists();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ids
     * @return Builder<TModel>
     */
    private function membershipConstraint(Builder $query, string $column, array $ids): Builder
    {
        if ($ids === []) {
            return $this->emptySet($query);
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function emptySet(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}
