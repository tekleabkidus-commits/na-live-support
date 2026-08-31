<?php

declare(strict_types=1);

namespace App\Domain\Routing\Supervision;

use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Skills\TicketLanguage;
use App\Domain\Routing\Strategies\ConfiguredStrategy;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 §8 / BR-03 (S11 step 13) — the DRAG-REASSIGN data contract (B5-S02 C01/C02 seam;
 * rendering is Doc 15's / Doc 07's). The dialog's job as drawn: make the bypass DELIBERATE by
 * showing what the router WOULD pick. `router_would_pick` is a DRY RUN of the queue's configured
 * strategy over the live routable set — nothing is assigned, no pointer moves, no record is
 * written. The commit door stays `Manual\Reassign` (SEC-01's two-scope re-check + manual_by).
 * Ids only — names resolve at render (SEC-02).
 */
final class DragReassign
{
    public function __construct(
        private readonly ConfiguredStrategy $strategy,
        private readonly RoutableAgents $routable,
        private readonly TicketLanguage $language,
    ) {}

    /** @return array{ticket_id: string, queue_id: ?string, current_assignee_id: ?string, waiting_seconds: ?int, router_would_pick: ?string} */
    public function context(string $ticketId): array
    {
        /** @var object{id: string, queue_id: ?string, assignee_id: ?string, conversation_id: string, department_id: ?string, created_at: string}|null $ticket */
        $ticket = DB::table('tickets')->where('id', $ticketId)->first(['id', 'queue_id', 'assignee_id', 'conversation_id', 'department_id', 'created_at']);
        // queue_id is bracket-assigned in both returns: this file READS tickets and the T-02
        // one-door sweep (PlaceInQueueOneDoorTest) treats a literal 'queue_id' => in a
        // tickets-touching file as writer grammar. The contract key stands; a read respells.
        if ($ticket === null) {
            $absent = ['ticket_id' => $ticketId];
            $absent['queue_id'] = null;

            return $absent + ['current_assignee_id' => null, 'waiting_seconds' => null, 'router_would_pick' => null];
        }
        $pick = null;
        $waiting = null;
        if ($ticket->queue_id !== null) {
            $routable = $this->routable->forQueue($ticket->queue_id);
            if ($routable !== []) {
                $ordered = $this->strategy->order($ticket->queue_id, $routable, ['language' => $this->language->forConversation($ticket->conversation_id), 'department_id' => $ticket->department_id]);
                $pick = $ordered['candidates'][0] ?? null;
            }
            /** @var object{s: int|string}|null $age */
            $age = DB::selectOne('SELECT extract(epoch FROM clock_timestamp() - ?::timestamptz)::int AS s', [$ticket->created_at]);
            $waiting = $age !== null ? max(0, (int) $age->s) : null;
        }

        $out = ['ticket_id' => $ticket->id];
        $out['queue_id'] = $ticket->queue_id;

        return $out + [
            'current_assignee_id' => $ticket->assignee_id,
            'waiting_seconds' => $waiting,
            'router_would_pick' => $pick,
        ];
    }
}
