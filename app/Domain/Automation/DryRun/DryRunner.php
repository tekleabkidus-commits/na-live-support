<?php

declare(strict_types=1);

namespace App\Domain\Automation\DryRun;

use App\Domain\Automation\Actions\ActionRegistry;
use App\Domain\Automation\Engine\ConditionTree;
use App\Domain\Automation\Engine\EventContext;
use App\Domain\Automation\Engine\Guards;
use App\Domain\Automation\Rules\SystemActorFloor;
use App\Domain\Routing\Hours\HoursContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-05 / AC-02 / NFR-03 / §9 — the dry-run tester.
 *
 * ZERO SIDE EFFECTS BY CONSTRUCTION: this class holds no writer, publishes no
 * event, inserts no execution row and dispatches no chain — it evaluates the
 * SAME ConditionTree over the SAME EventContext assembly the live Evaluator
 * uses (AC-02's parity is shared code, not a re-implementation; the Doc 09
 * AC-03 simulation-vs-replay pattern). Per-condition truth badges (§9's
 * mandatory affordance) are the tree's own trace. Sample sources: a live
 * ticket (synthesized present-tense context), a HISTORICAL event from the
 * last 7 days (FR-05's picker), or a pasted fact map.
 */
final class DryRunner
{
    public function __construct(
        private readonly ConditionTree $conditions,
        private readonly HoursContract $hours,
        private readonly SystemActorFloor $floor,
    ) {}

    /**
     * Dry-run one rule definition against a fact map.
     *
     * @param  array{conditions?: array<string, mixed>, actions?: list<array<string, mixed>>}  $definition
     * @param  array<string, mixed>  $facts
     * @param  array<string, bool>|null  $floorCapture  the revision's captured floor (null = simulate the given user's)
     * @return array{matched: bool, trace: list<array{fact: string, op: string, value: mixed, actual: mixed, matched: bool}>, actions: list<array{type: string, would_run: bool, note: ?string}>, guards: list<string>}
     */
    public function run(array $definition, array $facts, ?array $floorCapture = null, ?User $simulateActivator = null): array
    {
        $result = $this->conditions->evaluate($definition['conditions'] ?? ['all' => []], $facts);
        $floorCapture ??= $simulateActivator !== null ? $this->floor->captureFor($simulateActivator) : null;

        $actions = [];
        $guards = [];
        $count = 0;
        foreach ($definition['actions'] ?? [] as $action) {
            $type = is_string($action['type'] ?? null) ? $action['type'] : 'unknown';
            $count++;
            $note = null;
            $wouldRun = $result['matched'];
            if ($count > Guards::MAX_ACTIONS_PER_EVENT) {
                $wouldRun = false;
                $note = 'max_actions';
                if (! in_array('max_actions', $guards, true)) {
                    $guards[] = 'max_actions';
                }
            } elseif (! in_array($type, ActionRegistry::TYPES, true)) {
                $wouldRun = false;
                $note = 'unknown_action';
            } elseif ($floorCapture !== null && ($floorCapture[$type] ?? false) !== true) {
                $wouldRun = false;
                $note = 'floor_refused';
            }
            $actions[] = ['type' => $type, 'would_run' => $wouldRun, 'note' => $note];
        }

        return ['matched' => $result['matched'], 'trace' => $result['trace'], 'actions' => $actions, 'guards' => $guards];
    }

    /**
     * The context a HISTORICAL event (last 7 days — FR-05's picker) or a live
     * ticket would evaluate under — the SAME assembly the Evaluator uses.
     *
     * @return array<string, mixed>
     */
    public function contextForEvent(string $eventId): array
    {
        return EventContext::assemble($eventId, $this->hours)->facts;
    }

    /** @return list<object{event_id: string, name: string, occurred_at: string}> the FR-05 7-day picker rows */
    public function recentEvents(int $limit = 100): array
    {
        /** @var list<object{event_id: string, name: string, occurred_at: string}> $rows */
        $rows = DB::select(
            "SELECT event_id, name, occurred_at FROM outbox
             WHERE occurred_at > clock_timestamp() - interval '7 days'
             ORDER BY id DESC LIMIT ?",
            [$limit],
        );

        return $rows;
    }

    /**
     * AC-02's replay: the dry-run verdict for every rule that LIVE-evaluated an
     * event, compared against the recorded executions — equality is the proof.
     *
     * @return list<array{rule_id: string, live_matched: bool, dry_matched: bool, equal: bool}>
     */
    public function replay(string $eventId): array
    {
        $facts = $this->contextForEvent($eventId);
        $out = [];
        /** @var list<object{rule_id: string, matched: bool, definition: string}> $executions */
        $executions = DB::select(
            'SELECT e.rule_id, e.matched, v.definition FROM automation_rule_executions e
             JOIN automation_rule_revisions v ON v.id = e.revision_id
             WHERE e.event_id = ? ORDER BY e.id',
            [$eventId],
        );
        foreach ($executions as $execution) {
            /** @var array{conditions?: array<string, mixed>} $definition */
            $definition = json_decode($execution->definition, true, 32, JSON_THROW_ON_ERROR);
            $dry = $this->conditions->evaluate($definition['conditions'] ?? ['all' => []], $facts);
            $out[] = [
                'rule_id' => $execution->rule_id,
                'live_matched' => (bool) $execution->matched,
                'dry_matched' => $dry['matched'],
                'equal' => ((bool) $execution->matched) === $dry['matched'],
            ];
        }

        return $out;
    }
}
