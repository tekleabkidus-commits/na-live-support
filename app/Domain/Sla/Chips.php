<?php

declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Sla\Timers\SlaTimers;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-11 — the SLA chip CONTRACT (Doc 07/06 render): stage label key +
 * countdown target, state green→amber(warned)→red(breached) as STEP changes
 * (no animation — §9), pause glyph with reason, MOST URGENT stage when several
 * are live. Doc 07 renders this shape; `fixtures/sla/doc07-chip-contract.json`
 * pins it and SlaChipContractTest holds both sides to it.
 */
final class Chips
{
    public function __construct(private readonly SlaTimers $timers) {}

    /**
     * @return array{stage: string, label_key: string, state: string, target_at: ?string, paused: ?array{reason: string}}|null
     */
    public function forTicket(string $ticketId): ?array
    {
        /** @var object{id: string, policy_snapshot: string, calendar_snapshot: ?string}|null $instance */
        $instance = DB::table('sla_instances')->where('ticket_id', $ticketId)->first();
        if ($instance === null) {
            return null;
        }
        /** @var list<object{id: string, stage: string, breached: bool, warned: bool, pauses: string, target_at: ?string, clock_start_at: string, retargeted: ?string, target_us: int|string, cleared_at: ?string}> $rows */
        $rows = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->whereNull('cleared_at')->get()->all();
        if ($rows === []) {
            return null;
        }
        /** @var array{warning_pct?: int} $snapshot */
        $snapshot = json_decode($instance->policy_snapshot, true, 16) ?: [];
        /** @var array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null $calendar */
        $calendar = $instance->calendar_snapshot === null ? null : json_decode($instance->calendar_snapshot, true, 16);
        $calendar = is_array($calendar) ? $calendar : null;

        $best = null;
        $bestRank = -1;
        $bestDue = PHP_INT_MAX;
        foreach ($rows as $row) {
            $math = $this->timers->stageMath($row, $calendar, $snapshot['warning_pct'] ?? 80);
            $state = ((bool) $row->breached) ? 'red' : (((bool) $row->warned) ? 'amber' : 'green');
            $rank = ['green' => 0, 'amber' => 1, 'red' => 2][$state];
            $due = $math['breach_at_us'] ?? PHP_INT_MAX;
            // Most urgent: worst state first, then the nearest breach instant.
            if ($rank > $bestRank || ($rank === $bestRank && $due < $bestDue)) {
                $bestRank = $rank;
                $bestDue = $due;
                $paused = null;
                if ($math['paused']) {
                    /** @var list<array{reason: string, until: ?string}> $pauses */
                    $pauses = json_decode((string) $row->pauses, true, 8) ?: [];
                    foreach ($pauses as $pause) {
                        if ($pause['until'] === null) {
                            $paused = ['reason' => $pause['reason']];
                            break;
                        }
                    }
                }
                $best = [
                    'stage' => $row->stage,
                    'label_key' => 'workflow.sla.stage.'.$row->stage,
                    'state' => $state,
                    'target_at' => is_string($row->target_at) ? $row->target_at : null,
                    'paused' => $paused,
                ];
            }
        }

        return $best;
    }
}
