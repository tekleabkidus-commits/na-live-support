<?php

declare(strict_types=1);

namespace App\Domain\Routing\Record;

use App\Domain\Tickets\Storage\TicketScope;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

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
    private const AGENT_KEYS = ['candidate', 'by', 'assigned_to', 'routable', 'candidates', 'pointer', 'next', 'manual_by', 'old_assignee_id', 'new_assignee_id', 'hold_for', 'previous_assignee_id'];

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
            // §14 (S11 step 14): the localized SENTENCE, composed server-side in the viewer's
            // locale from routing.record.* ICU keys — variant from the stored code or derived
            // from the step's own input; null when neither answers (old records) and the UI
            // falls back to the stored English prose. Slots are counts/codes only, never ids.
            $steps[] = ['step' => $step['step'], 'input' => $input, 'outcome' => $step['outcome'], 'sentence' => $this->sentence($step)];
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
            // S11 step 11b (STOP-G): the drawn card renders NAMES, not raw ids — the map exists
            // ONLY when the agent facts themselves do (SEC-02 by absence, same key law).
            $ids = [];
            foreach ($steps as $step) {
                foreach (self::AGENT_KEYS as $k) {
                    $v = $step['input'][$k] ?? null;
                    foreach (is_array($v) ? $v : [$v] as $one) {
                        if (is_string($one) && strlen($one) === 36) {
                            $ids[$one] = true;
                        }
                    }
                }
            }
            if (is_string($row->assigned_to)) {
                $ids[$row->assigned_to] = true;
            }
            /** @var array<string, string> $names */
            $names = $ids === [] ? [] : DB::table('users')->whereIn('id', array_keys($ids))->pluck('name', 'id')->all();
            $out['names'] = $names;
        }

        return $out;
    }

    /**
     * @param  array{step: string, input: array<string, mixed>, outcome: string, code?: string}  $step
     */
    private function sentence(array $step): ?string
    {
        $in = $step['input'];
        $key = $step['code'] ?? $this->derive($step['step'], $in);
        if ($key === null) {
            return null;
        }
        if (! Lang::has('routing.record.'.$key)) {
            return null;
        }

        return (string) __('routing.record.'.$key, $this->scalar($this->slots($key, $in)));
    }

    /**
     * The variants the stored INPUT alone can tell apart (older records carry no code).
     *
     * @param  array<string, mixed>  $in
     */
    private function derive(string $step, array $in): ?string
    {
        return match ($step) {
            'department' => match ($in['source'] ?? null) {
                'explicit' => 'department.explicit',
                'ai_suggestion' => ($in['autoaccept'] ?? false) ? 'department.ai_accepted' : 'department.ai_flagged',
                'connection_tag' => isset($in['department_id']) ? 'department.connection_tag' : 'department.connection_tag_miss',
                'tenant_default' => ($in['ai_flagged'] ?? false) ? 'department.tenant_default_flagged' : 'department.tenant_default',
                default => null,
            },
            'queue' => match ($in['source'] ?? null) {
                'explicit' => 'queue.explicit', 'routing_tag' => 'queue.routing_tag', 'department_default' => 'queue.department_default', default => null,
            },
            'rg_override' => isset($in['queue_id']) ? 'rg.forced' : 'rg.missing',
            'position' => isset($in['starved']) ? (($in['starved'] === true) ? 'position.starved' : 'position.scored') : null,
            'eligibility' => ($in['routable'] ?? null) === 0 ? 'eligibility.none' : (isset($in['note_code']) ? 'eligibility.ok' : null),
            'assign' => isset($in['by']) ? 'assign.claimed' : null,
            'wait' => 'wait',
            'engine_error' => 'engine_error',
            'courtesy_hold' => 'courtesy_hold',
            'manual_reassign' => 'manual_reassign',
            'rescue_return' => 'rescue_return',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function slots(string $key, array $in): array
    {
        $slots = match (true) {
            $key === 'position.scored' => ['score' => $in['score'] ?? '', 'boosts' => ($in['matched'] ?? []) === [] ? __('routing.record.no_boosts') : implode(', ', array_map(static fn (mixed $m): string => is_scalar($m) ? (string) $m : '', (array) ($in['matched'] ?? [])))],
            $key === 'position.starved' => ['cap' => $in['cap_minutes'] ?? ''],
            $key === 'eligibility.ok' => ['count' => $in['routable'] ?? ''],
            $key === 'courtesy_hold' => ['status' => $in['assignee_status'] ?? '', 'minutes' => $in['minutes'] ?? ''],
            default => [],
        };
        if ($key === 'eligibility.ok' && isset($in['note_code']) && is_string($in['note_code'])) {
            $slots['strategy'] = $this->noteSentence($in['note_code'], $this->scalar((array) ($in['note_slots'] ?? [])));
            if (isset($in['then_code']) && is_string($in['then_code'])) {
                $slots['strategy'] .= ' — '.$this->noteSentence($in['then_code'], $this->scalar((array) ($in['then_slots'] ?? [])));
            }
        }

        return $slots;
    }

    /**
     * Slot values flattened to display scalars (a non-scalar slot renders empty, never leaks).
     *
     * @param  array<mixed, mixed>  $values
     * @return array<string, string>
     */
    private function scalar(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /** @param  array<string, string>  $slots */
    private function noteSentence(string $code, array $slots): string
    {
        // §14's last clause: the skill LANGUAGE renders as its localized name (ref.language).
        if (isset($slots['language']) && Lang::has('ref.language.'.$slots['language'])) {
            $slots['language'] = (string) __('ref.language.'.$slots['language']);
        }

        return Lang::has('routing.record.'.$code) ? (string) __('routing.record.'.$code, $slots) : $code;
    }
}
