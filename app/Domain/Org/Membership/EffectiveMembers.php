<?php

declare(strict_types=1);

namespace App\Domain\Org\Membership;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-04 / A-50 point 4 — a queue's EFFECTIVE agents:
 *   (direct ∪ via-team ∪ unexpired temporary queue members)
 *   ∩ {users whose ticket-view scope REACHES the queue's department}
 * where reach = All → always; Dept → user_departments contains it; Team → the team's
 * queue reach contains it; Own → satisfied by the assignment itself (A-11); None → never.
 * A member failing the intersection is a CONFIGURATION ERROR (FR-04): EXCLUDED from
 * routing and BADGED — `config_error` names the department the agent cannot see (J06/J12's
 * warning text; Doc 03 EC-04's drawn warning state, arriving here). Eligibility (FR-05)
 * consumes `eligible()`; the admin screens consume `members()` for the provenance chips.
 */
final class EffectiveMembers
{
    public function __construct(
        private readonly OrgMembership $membership,
        private readonly CapabilityMapProvider $maps,
    ) {}

    /**
     * Every member with provenance and the config-error flag.
     *
     * @return list<array{user_id: string, via: 'direct'|'team'|'temporary', team_id: ?string, expires_at: ?string, config_error: bool, unreachable_department_id: ?string}>
     */
    public function members(string $queueId): array
    {
        /** @var object{department_id: string}|null $queue */
        $queue = DB::table('queues')->where('id', $queueId)->whereNull('deleted_at')->first(['department_id']);
        if ($queue === null) {
            return [];
        }
        $departmentId = $queue->department_id;

        $rows = [];
        /** @var list<object{user_id: string, kind: string, expires_at: ?string}> $direct */
        $direct = DB::table('queue_members')->where('queue_id', $queueId)->whereNull('ended_at')
            ->where(static function (\Illuminate\Database\Query\Builder $q): void {
                $q->where('kind', 'direct')->orWhereRaw('expires_at > clock_timestamp()');
            })
            ->orderBy('user_id')->get(['user_id', 'kind', 'expires_at'])->all();
        foreach ($direct as $m) {
            $rows[$m->user_id] = ['user_id' => $m->user_id, 'via' => $m->kind === 'temporary' ? 'temporary' : 'direct', 'team_id' => null, 'expires_at' => $m->expires_at];
        }
        /** @var list<object{user_id: string, team_id: string}> $viaTeam */
        $viaTeam = DB::table('queue_teams as qt')->join('team_members as tm', 'tm.team_id', '=', 'qt.team_id')->join('teams as t', 't.id', '=', 'qt.team_id')
            ->whereNull('t.deleted_at')->where('qt.queue_id', $queueId)->orderBy('tm.user_id')->get(['tm.user_id', 'qt.team_id'])->all();
        foreach ($viaTeam as $m) {
            $rows[$m->user_id] ??= ['user_id' => $m->user_id, 'via' => 'team', 'team_id' => $m->team_id, 'expires_at' => null];
        }

        $out = [];
        foreach ($rows as $userId => $row) {
            $user = User::query()->find($userId);
            $reaches = $user instanceof User && $this->reaches($user, $departmentId);
            $out[] = $row + ['config_error' => ! $reaches, 'unreachable_department_id' => $reaches ? null : $departmentId];
        }

        return $out;
    }

    /**
     * FR-04/FR-05: the members routing may consider — config errors EXCLUDED.
     *
     * @return list<string>
     */
    public function eligible(string $queueId): array
    {
        return array_values(array_map(fn (array $m): string => $m['user_id'], array_filter($this->members($queueId), fn (array $m): bool => ! $m['config_error'])));
    }

    /** A-50 point 4: does the user's ticket-view scope reach the department? */
    public function reaches(User $user, string $departmentId): bool
    {
        $map = $this->maps->mapFor($user);
        if (! $map->allows('tickets.view')) {
            return false;
        }

        return match ($map->scopeFor('tickets')) {
            'All', 'Own' => true,                                                             // Own: the assignment itself (A-11)
            'Dept' => in_array($departmentId, $this->membership->departmentIdsFor($user), true),
            'Team' => in_array($departmentId, $this->membership->teamReachDepartmentIdsFor($user), true),
            default => false,
        };
    }
}
