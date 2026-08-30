<?php

declare(strict_types=1);

namespace App\Support\Rbac\Scopes;

/**
 * How one scoped aggregate maps the three scope dimensions onto its columns
 * (Doc 03 FR-03): Own = "assigned to/created by the user", Team/Department =
 * membership columns. Each consuming model declares its own (Doc 04's
 * conversations, Doc 06's tickets, Doc 08's customers) — the CONSTRAINT logic
 * stays in ScopeSets, once (R-03).
 */
final readonly class ScopeColumns
{
    /**
     * @param  'team'|'department'  $teamColumnHolds  what the `team` column CONTAINS — team ids
     *                                                (Doc 03's fixture table) or department ids
     *                                                (tickets/list items: the team's queue reach,
     *                                                Doc 11 A-50 point 3). ScopeSets picks the id
     *                                                source by it.
     */
    public function __construct(
        public string $owner,
        public string $team,
        public string $department,
        public string $teamColumnHolds = 'team',
    ) {}
}
