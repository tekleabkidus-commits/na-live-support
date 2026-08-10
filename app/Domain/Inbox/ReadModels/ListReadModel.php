<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Rbac\Scopes\ScopeColumns;
use App\Support\Rbac\Scopes\ScopeSets;

/**
 * The scoped hydration path (Doc 07 T-01's "rows hydrate from
 * ticket_list_items by id batch") — and the SECOND of the two scope layers:
 *
 *   layer 1  membership: per-user views are per-user by key; shared views'
 *            per-scope variants are A-30's step-4 materialization.
 *   layer 2  HYDRATION (this class): every page is filtered through Doc 03's
 *            ScopeSets — THE one constraint implementation (R-03), applied to
 *            the list-item row's own scope columns. An id that reaches a ZSET
 *            wrongly still cannot hydrate out of scope, and a foreign
 *            tenant's id cannot hydrate at all (RLS on ticket_list_items).
 *
 * BR-03: what a viewer cannot see is ABSENT — rows outside scope are dropped
 * with no placeholder, no count residue, no lock icon.
 *
 * SABOTAGE #1's TARGET: remove the ScopeSets::apply call and the scoped-list
 * proof in tests/Security/InboxSecurityTest.php goes red.
 */
final class ListReadModel
{
    public function __construct(
        private readonly ViewZsets $zsets,
        private readonly ScopeSets $scopes,
    ) {}

    /** ticket_list_items carries the same scope columns tickets does (A-7's mapping). */
    public static function columns(): ScopeColumns
    {
        return new ScopeColumns(
            owner: 'assignee_id',
            team: 'department_id',
            department: 'department_id',
        );
    }

    /**
     * One page of hydrated rows for a view, in ZSET order, scope-filtered.
     *
     * @return list<object>
     */
    public function page(string $viewKey, string $view, User $viewer, int $offset = 0, int $limit = 50): array
    {
        $ids = $this->zsets->ids($viewKey, $offset, $limit, SystemViews::descending($view));
        if ($ids === []) {
            return [];
        }

        $query = TicketListItem::query()->whereIn('ticket_id', $ids);

        // Layer 2 — Doc 03's decision, never re-implemented (R-03).
        $this->scopes->apply($query, 'tickets', $viewer, self::columns());

        /** @var array<string, TicketListItem> $rows */
        $rows = $query->get()->keyBy('ticket_id')->all();

        // ZSET order preserved; out-of-scope ids vanish silently (BR-03).
        $page = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $page[] = $rows[$id];
            }
        }

        return $page;
    }
}
