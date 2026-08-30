<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-10 / AC-02 — LOAD-BASED: least open-assigned count; tie → least-recently-assigned
 * (agent_assignment_stats.last_assigned_at, the FLAG-F database stamp; never assigned sorts
 * first); final tiebreak by user id. Every tiebreak documented here and asserted in
 * LoadBasedTest (NFR-03: identical inputs → identical order).
 */
final class LoadBased implements AssignmentStrategy
{
    public function name(): string
    {
        return 'load_based';
    }

    public function order(string $queueId, array $routable, array $ticket): array
    {
        if ($routable === []) {
            return ['candidates' => [], 'note' => 'no routable agent'];
        }
        $ids = array_column($routable, 'user_id');
        /** @var array<string, ?string> $lastAssigned */
        $lastAssigned = DB::table('agent_assignment_stats')->whereIn('user_id', $ids)->pluck('last_assigned_at', 'user_id')->all();
        $rows = $routable;
        usort($rows, static function (array $a, array $b) use ($lastAssigned): int {
            if ($a['load'] !== $b['load']) {
                return $a['load'] <=> $b['load'];                                   // least open-assigned
            }
            $la = $lastAssigned[$a['user_id']] ?? null;
            $lb = $lastAssigned[$b['user_id']] ?? null;
            if ($la !== $lb) {
                if ($la === null) {
                    return -1;                                                      // never assigned first
                }
                if ($lb === null) {
                    return 1;
                }

                return strcmp($la, $lb);                                            // least-recently-assigned
            }

            return strcmp($a['user_id'], $b['user_id']);                            // user id — the floor
        });
        $candidates = array_column($rows, 'user_id');

        return ['candidates' => $candidates, 'note' => sprintf('least open-assigned (%d) → least-recently-assigned → user id', $rows[0]['load'])];
    }
}
