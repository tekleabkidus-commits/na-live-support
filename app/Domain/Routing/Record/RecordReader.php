<?php

declare(strict_types=1);

namespace App\Domain\Routing\Record;

use App\Domain\Tickets\Storage\TicketScope;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-17 / SEC-02 / AC-08 — ONE reader for routing records, elision by ROLE:
 * "reports.agents.individual gates the per-agent details inside expanded records for
 * non-supervisors; the record renders with agent names elided otherwise". The house law is
 * ABSENCE, not redaction: without the permission the per-agent keys do not exist in the
 * response (no `candidate`, no `routable`, no `candidates`, no `assigned_to` — only counts
 * and the plain-language outcomes with agent facts rewritten to counts). Scope is
 * re-checked on the ticket (SEC-01) — a record of a ticket the viewer cannot see is 404.
 */
final class RecordReader
{
    private const AGENT_KEYS = ['candidate', 'by', 'assigned_to', 'routable', 'candidates'];

    public function __construct(private readonly TicketScope $scope, private readonly CapabilityMapProvider $maps) {}

    /** @return array<string, mixed>|null */
    public function forViewer(User $viewer, string $ticketId, string $recordId): ?array
    {
        // SEC-01: the door's permission AND the ticket's scope, re-checked here so an internal caller
        // gets the same answer the route gives (tickets.view opens the door; scope decides the ticket).
        if (! $this->maps->mapFor($viewer)->allows('tickets.view') || ! $this->scope->visible($viewer, $ticketId)) {
            return null;
        }
        /** @var object{id: string, ticket_id: string, trigger: string, outcome: string, queue_id: ?string, department_id: ?string, assigned_to: ?string, strategy: ?string, eligible_count: ?int, record: string, at: string}|null $row */
        $row = DB::table('routing_decisions')->where('id', $recordId)->where('ticket_id', $ticketId)->first();
        if ($row === null) {
            return null;
        }
        $seesAgents = $this->maps->mapFor($viewer)->allows('reports.agents.individual');
        /** @var array{trigger: string, steps: list<array{step: string, input: array<string, mixed>, outcome: string}>, strategy: ?string, eligible_count: ?int, boosts_applied: list<string>, assigned_to: ?string, waiting: bool, outcome: string} $record */
        $record = json_decode($row->record, true, 16, JSON_THROW_ON_ERROR);

        $steps = [];
        foreach ($record['steps'] as $step) {
            $input = $step['input'];
            if (! $seesAgents) {
                foreach (self::AGENT_KEYS as $k) {
                    unset($input[$k]);
                }
            }
            $steps[] = ['step' => $step['step'], 'input' => $input, 'outcome' => $step['outcome']];
        }

        $out = [
            'id' => $row->id,
            'ticket_id' => $row->ticket_id,
            'trigger' => $row->trigger,
            'outcome' => $row->outcome,
            'queue_id' => $row->queue_id,
            'department_id' => $row->department_id,
            'strategy' => $row->strategy,
            'eligible_count' => $row->eligible_count,
            'boosts_applied' => $record['boosts_applied'],
            'waiting' => $record['waiting'],
            'steps' => $steps,
            'at' => $row->at,
        ];
        if ($seesAgents) {
            $out['assigned_to'] = $row->assigned_to;   // present ONLY for supervision-scope viewers (absence, not null)
        }

        return $out;
    }
}
