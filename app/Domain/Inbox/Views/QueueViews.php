<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Views;

use App\Domain\Routing\Waiting\WaitingSets;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Doc 07 FR-05's queue views, REAL (S11 step 11a; STOP-H countersigned 2026-08-30 — the
 * seam fixture and the `?seam_queues` door are deleted in this same commit).
 *
 * "Auto-generate per accessible queue": the viewer's queue views are the LIVE, ACTIVE
 * queues where they are an effective member — direct ∪ via team ∪ UNEXPIRED temporary
 * (the resolver time check, T-07's second path: an expired pull-in's view is GONE before
 * any sweep — Doc 07 EC-09/Doc 11 EC-07). Each view's membership is the queue's WAITING
 * set — unassigned open-family tickets in FR-08 step 3's own order (the dispatcher's
 * WaitingSets ZSET, the same scores): the agent sees the queue exactly as the router
 * serves it. The COUNT is the set's depth — one truth, no variant materialization
 * (membership in a queue is not scope-dependent: an effective member sees the queue's
 * waiting list whole; Doc 03 scope still governs every ticket OPEN through the thread).
 */
final class QueueViews
{
    public function __construct(private readonly WaitingSets $waiting) {}

    /** @return list<array{id: string, name: string, count: int}> */
    public function forViewer(User $user): array
    {
        $out = [];
        foreach ($this->memberQueueRows($user) as $row) {
            $out[] = ['id' => $row->id, 'name' => $row->name, 'count' => $this->waiting->depth($row->id)];
        }

        return $out;
    }

    /** Doc 07 EC-09: a viewer who is no longer an effective member has NO such view (the 404 pattern at the list door). */
    public function isMember(User $user, string $queueId): bool
    {
        foreach ($this->memberQueueRows($user) as $row) {
            if ($row->id === $queueId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<object{id: string, name: string}> */
    private function memberQueueRows(User $user): array
    {
        /** @var list<object{id: string, name: string}> $direct */
        $direct = DB::table('queue_members as qm')->join('queues as q', 'q.id', '=', 'qm.queue_id')
            ->whereNull('q.deleted_at')->where('q.is_active', true)
            ->where('qm.user_id', $user->identifier())->whereNull('qm.ended_at')
            ->where(static function (\Illuminate\Database\Query\Builder $w): void {
                $w->where('qm.kind', 'direct')->orWhereRaw('qm.expires_at > clock_timestamp()');
            })
            ->get(['q.id', 'q.name'])->all();
        /** @var list<object{id: string, name: string}> $viaTeam */
        $viaTeam = DB::table('queue_teams as qt')
            ->join('team_members as tm', 'tm.team_id', '=', 'qt.team_id')
            ->join('teams as t', 't.id', '=', 'qt.team_id')
            ->join('queues as q', 'q.id', '=', 'qt.queue_id')
            ->whereNull('t.deleted_at')->whereNull('q.deleted_at')->where('q.is_active', true)
            ->where('tm.user_id', $user->identifier())
            ->get(['q.id', 'q.name'])->all();
        $byId = [];
        foreach ([...$direct, ...$viaTeam] as $row) {
            $byId[$row->id] = $row;
        }
        $rows = array_values($byId);
        usort($rows, static fn (object $a, object $b): int => strcmp($a->name, $b->name) ?: strcmp($a->id, $b->id));

        return $rows;
    }
}
