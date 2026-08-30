<?php

declare(strict_types=1);

namespace App\Domain\Org\Membership;

use App\Models\User;
use App\Support\Rbac\Scopes\MembershipSource;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-04 / T-01 / Doc 03 T-03 / A-50 — THE REAL MEMBERSHIP SOURCE (S11 step 3, the
 * Doc 03 binding packet). The seam that has been fail-closed since S19 binds here.
 *
 * - teamIdsFor()             = live team_members rows (Doc 03 FR-03 "the user's teams").
 * - departmentIdsFor()       = live user_departments rows, direct ∪ UNEXPIRED temporary
 *                              (A-50 point 1: department access is a membership of its own).
 * - teamReachDepartmentIdsFor() = departments of the queues the user's teams are members of
 *                              (A-50 point 3: "records of the user's teams" for a ticket,
 *                              which has no team column — expressed on department_id).
 *
 * Expiry is the DATABASE's clock (C-11, Doc 03 FR-09/EC-09: "resolver time check, no cron
 * dependency"): an expired temporary row is invisible here BEFORE the 1-minute sweep ends
 * it (T-07's second path; AC-05's independence bar). Ended rows (ended_at) are never
 * members. A department or queue tombstone (deleted_at) removes its reach.
 *
 * Every read is under the caller's RLS binding; ids are returned SORTED so signatures
 * (ViewHash, CustomerScope) are stable across calls (A-30's variant keys).
 */
final class OrgMembership implements MembershipSource
{
    public function teamIdsFor(User $user): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('team_members as tm')
            ->join('teams as t', 't.id', '=', 'tm.team_id')
            ->whereNull('t.deleted_at')
            ->where('tm.user_id', $user->identifier())
            ->orderBy('tm.team_id')
            ->pluck('tm.team_id')
            ->all();

        return $ids;
    }

    public function departmentIdsFor(User $user): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('user_departments as ud')
            ->join('departments as d', 'd.id', '=', 'ud.department_id')
            ->whereNull('d.deleted_at')
            ->where('ud.user_id', $user->identifier())
            ->whereNull('ud.ended_at')
            ->where(static function (\Illuminate\Database\Query\Builder $q): void {
                $q->where('ud.kind', 'direct')
                    ->orWhereRaw('ud.expires_at > clock_timestamp()');   // the resolver time check
            })
            ->orderBy('ud.department_id')
            ->distinct()
            ->pluck('ud.department_id')
            ->all();

        return $ids;
    }

    public function teamReachDepartmentIdsFor(User $user): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('team_members as tm')
            ->join('teams as t', 't.id', '=', 'tm.team_id')
            ->join('queue_teams as qt', 'qt.team_id', '=', 'tm.team_id')
            ->join('queues as q', 'q.id', '=', 'qt.queue_id')
            ->join('departments as d', 'd.id', '=', 'q.department_id')
            ->whereNull('t.deleted_at')
            ->whereNull('q.deleted_at')
            ->whereNull('d.deleted_at')
            ->where('tm.user_id', $user->identifier())
            ->orderBy('q.department_id')
            ->distinct()
            ->pluck('q.department_id')
            ->all();

        return $ids;
    }
}
