<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

use App\Domain\Routing\Hours\HoursContract;
use App\Jobs\Automation\ActionChainJob;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * Doc 12 FR-04 / T-02 / T-03 / BR-01 / EC-12 / R-04 — the per-event evaluation.
 *
 * Deterministic order everywhere (BR-01): candidates by (position, id); a
 * matching rule's actions dispatch as ONE ordered chain job (per-action
 * try/log/continue lives in the chain — AC-01). Guards: ≤10 rules fire, ≤20
 * total actions, chain depth ≤3 (from envelope metadata — T-03), per-(rule,
 * ticket) cooldown (EC-12). Every guard trip writes a loop_guard entry AND
 * publishes automation.loop_guard.tripped (FR-04's alert arm). The R-04
 * rules-pause switch short-circuits EVALUATION only — armed SLA instances,
 * logs and state are untouched (N-19).
 *
 * Evaluation itself is synchronous and cheap (FS-01): actions are async on the
 * automation queue; SLA clocks ride their own timers — deadlines never slip
 * because actions queue.
 */
final class Evaluator
{
    public function __construct(
        private readonly RuleCompiler $compiler,
        private readonly ConditionTree $conditions,
        private readonly Guards $guards,
        private readonly HoursContract $hours,
        private readonly EventPublisher $events,
    ) {}

    /** Evaluate one relayed event for one trigger. Idempotent on (rule, event) by the executions unique. */
    public function evaluate(string $eventId, string $trigger): void
    {
        if (Feature::active('automation.rules_pause')) {
            return; // R-04: paused tenants evaluate nothing — instantly, reversibly.
        }
        $context = EventContext::assemble($eventId, $this->hours);
        if ($context->facts === []) {
            return; // unknown/foreign event — nothing to evaluate
        }
        if ($context->automationDepth >= Guards::MAX_CHAIN_DEPTH) {
            $this->trip($eventId, null, $context->ticketId, 'chain_depth');

            return; // T-03: depth ≥ 3 → evaluation SKIP with loop_guard log + alert
        }

        $fired = 0;
        $actionsDispatched = 0;
        foreach ($this->compiler->activeRulesFor($trigger) as $rule) {
            if ($fired >= Guards::MAX_RULES_PER_EVENT) {
                $this->trip($eventId, $rule['id'], $context->ticketId, 'max_rules');
                break;
            }
            if ($this->guards->inCooldown($rule['id'], $context->ticketId, $rule['cooldown_seconds'])) {
                continue; // EC-12: suppressed for THIS ticket; logged as unmatched below? No row — cooldown suppression is not an evaluation.
            }
            $result = $this->conditions->evaluate($rule['conditions'], $context->facts);
            $actions = $result['matched'] ? $rule['actions'] : [];
            $trips = [];
            if ($result['matched'] && $actionsDispatched + count($actions) > Guards::MAX_ACTIONS_PER_EVENT) {
                $actions = array_slice($actions, 0, Guards::MAX_ACTIONS_PER_EVENT - $actionsDispatched);
                $trips[] = 'max_actions';
            }

            // The executions table is INSERT-only by grant (amendment item 3), so a
            // rule's own guard trips ride ITS row AT INSERT — never a later update.
            $executionId = (string) Str::uuid7();
            $inserted = DB::table('automation_rule_executions')->insertOrIgnore([
                'id' => $executionId,
                'tenant_id' => DB::table('automation_rules')->where('id', $rule['id'])->value('tenant_id'),
                'rule_id' => $rule['id'],
                'revision_id' => $rule['revision_id'],
                'event_id' => $eventId,
                'context_ref' => json_encode(array_filter(['ticket_id' => $context->ticketId]), JSON_THROW_ON_ERROR),
                'condition_results' => json_encode($result['trace'], JSON_THROW_ON_ERROR),
                'action_outcomes' => '[]',
                'guard_trips' => json_encode($trips, JSON_THROW_ON_ERROR),
                'matched' => $result['matched'],
            ]);
            if ($inserted === 0) {
                continue; // C-09: this (rule, event) already evaluated — a redelivery changes nothing
            }
            if (! $result['matched']) {
                continue;
            }
            $fired++;
            $actionsDispatched += count($actions);
            $tenantId = DB::table('automation_rules')->where('id', $rule['id'])->value('tenant_id');
            DB::transaction(fn (): string => $this->events->publish('automation.rule.executed', [
                'tenant_id' => is_string($tenantId) ? $tenantId : '',
                'rule_id' => $rule['id'],
                'revision_id' => $rule['revision_id'],
                'event_id' => $eventId,
                'ticket_id' => $context->ticketId,
                'matched' => true,
            ]));
            foreach ($trips as $guard) {
                $this->trip($eventId, $rule['id'], $context->ticketId, $guard);
            }
            if ($actions !== []) {
                ActionChainJob::dispatch($executionId, $actions, $context->automationDepth + 1)->onQueue('automation');
            }
        }
    }

    private function trip(string $eventId, ?string $ruleId, ?string $ticketId, string $guard): void
    {
        Log::warning('automation loop_guard tripped (FR-04)', ['event_id' => $eventId, 'rule_id' => $ruleId, 'guard' => $guard]);
        DB::transaction(fn (): string => $this->events->publish('automation.loop_guard.tripped', [
            'tenant_id' => app(\App\Support\Tenancy\TenantContext::class)->tenantId(),
            'rule_id' => $ruleId,
            'ticket_id' => $ticketId,
            'guard' => $guard,
        ]));
    }
}
