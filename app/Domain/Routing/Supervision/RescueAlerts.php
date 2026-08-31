<?php

declare(strict_types=1);

namespace App\Domain\Routing\Supervision;

use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-16 / §8 (S11 step 13) — the RESCUE ALERT data contract (B5-S02 C12/C13/C14 seam;
 * rendering is Doc 07's Rescue view chrome / Doc 16's bell). One row per LIVE condition:
 * `heartbeat_lost` / `orphaned` from `rescue_flags` (grant-physics table), `channel_unreachable`
 * from Doc 05's ticket column. `auto_return` rides EVERY row because the drawing's whole point
 * is that this single setting decides whether the tickets are already safe or still stranded.
 * Ids only (SEC-02) — names resolve at render.
 */
final class RescueAlerts
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<array{kind: string, ticket_id: string, assignee_id: ?string, since_seconds: ?int, auto_return: bool}> newest first (unreachable rows carry no timestamp — Doc 05's column is a boolean) */
    public function rows(int $limit = 50): array
    {
        $auto = (bool) $this->settings->get('routing.rescue.auto');
        /** @var list<object{reason: string, ticket_id: string, assignee_id: ?string, s: int|string}> $flags */
        $flags = DB::table('rescue_flags')
            ->join('tickets', 'tickets.id', '=', 'rescue_flags.ticket_id')
            ->whereNull('rescue_flags.cleared_at')
            ->selectRaw('rescue_flags.reason, rescue_flags.ticket_id, tickets.assignee_id, extract(epoch FROM clock_timestamp() - rescue_flags.flagged_at)::int AS s')
            ->orderByDesc('rescue_flags.flagged_at')->limit($limit)->get()->all();
        /** @var list<object{id: string, assignee_id: ?string}> $unreachable */
        $unreachable = DB::table('tickets')->where('channel_unreachable', true)
            ->orderByDesc('last_status_changed_at')->limit($limit)->get(['id', 'assignee_id'])->all();

        $rows = [];
        foreach ($flags as $flag) {
            $rows[] = ['kind' => $flag->reason, 'ticket_id' => $flag->ticket_id, 'assignee_id' => $flag->assignee_id, 'since_seconds' => max(0, (int) $flag->s), 'auto_return' => $auto];
        }
        foreach ($unreachable as $row) {
            $rows[] = ['kind' => 'channel_unreachable', 'ticket_id' => $row->id, 'assignee_id' => $row->assignee_id, 'since_seconds' => null, 'auto_return' => false];
        }

        return array_slice($rows, 0, $limit);
    }
}
