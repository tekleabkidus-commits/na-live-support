<?php

declare(strict_types=1);

namespace App\Domain\Automation\ExecutionLog;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-06 / SEC-03 / US-06 — the execution log's read side.
 *
 * Per-rule: matched count, last run, per-execution detail (event ref,
 * condition results, action outcomes incl. failures). Per-ticket: the "what
 * touched this ticket" view (rule links — the Doc 06 FR-26 timeline consumes
 * these rows through its own render). SEC-03: condition traces may embed
 * MESSAGE CONTENT (the `contains`/keyword actuals) — for viewers without
 * `automation.rules.view` the actual VALUES of message facts are elided to
 * matched-keyword markers; ids and non-message facts stay (absence of prose,
 * not absence of record).
 */
final class LogReader
{
    public function __construct(private readonly CapabilityMapProvider $capabilities) {}

    /**
     * @return array{matched_count: int, last_run_at: ?string, executions: list<array<string, mixed>>}
     */
    public function forRule(string $ruleId, User $viewer, int $limit = 50): array
    {
        /** @var list<object{id: string, event_id: string, matched: bool, executed_at: string, condition_results: string, action_outcomes: string, guard_trips: string, context_ref: string}> $rows */
        $rows = DB::select(
            'SELECT id, event_id, matched, executed_at, condition_results, action_outcomes, guard_trips, context_ref
             FROM automation_rule_executions WHERE rule_id = ? ORDER BY executed_at DESC LIMIT ?',
            [$ruleId, $limit],
        );
        $matchedCount = (int) DB::table('automation_rule_executions')->where('rule_id', $ruleId)->where('matched', true)->count();
        $elide = ! $this->capabilities->mapFor($viewer)->allows('automation.rules.view');

        $executions = [];
        foreach ($rows as $row) {
            /** @var list<array{fact: string, op: string, value: mixed, actual: mixed, matched: bool}> $trace */
            $trace = json_decode($row->condition_results, true, 16) ?: [];
            if ($elide) {
                foreach ($trace as $i => $leaf) {
                    if (str_starts_with($leaf['fact'], 'message.')) {
                        // SEC-03: the marker says WHETHER it matched, never the prose.
                        $trace[$i]['actual'] = $leaf['matched'] ? '[matched]' : '[no match]';
                        $trace[$i]['value'] = is_string($leaf['value']) ? '[keyword]' : $leaf['value'];
                    }
                }
            }
            $executions[] = [
                'id' => $row->id,
                'event_id' => $row->event_id,
                'matched' => (bool) $row->matched,
                'executed_at' => $row->executed_at,
                'conditions' => $trace,
                'action_outcomes' => json_decode($row->action_outcomes, true, 16) ?: [],
                'guard_trips' => json_decode($row->guard_trips, true, 4) ?: [],
                'ticket_id' => (static function (string $contextRef): ?string {
                    $decoded = json_decode($contextRef, true, 4);

                    return is_array($decoded) && is_string($decoded['ticket_id'] ?? null) ? $decoded['ticket_id'] : null;
                })($row->context_ref),
            ];
        }
        /** @var object{last: ?string}|null $last */
        $last = DB::selectOne('SELECT max(executed_at) AS last FROM automation_rule_executions WHERE rule_id = ?', [$ruleId]);

        return ['matched_count' => $matchedCount, 'last_run_at' => $last?->last, 'executions' => $executions];
    }

    /**
     * US-06/FR-06: every rule touch on one ticket, with the rule link — the
     * timeline's source rows.
     *
     * @return list<array{execution_id: string, rule_id: string, rule_name: string, executed_at: string, action_outcomes: list<array<string, mixed>>}>
     */
    public function forTicket(string $ticketId, int $limit = 50): array
    {
        /** @var list<object{id: string, rule_id: string, name: string, executed_at: string, action_outcomes: string}> $rows */
        $rows = DB::select(
            "SELECT e.id, e.rule_id, r.name, e.executed_at, e.action_outcomes
             FROM automation_rule_executions e JOIN automation_rules r ON r.id = e.rule_id
             WHERE e.matched AND e.context_ref->>'ticket_id' = ? ORDER BY e.executed_at DESC LIMIT ?",
            [$ticketId, $limit],
        );
        $out = [];
        foreach ($rows as $row) {
            /** @var list<array<string, mixed>> $outcomes */
            $outcomes = json_decode($row->action_outcomes, true, 16) ?: [];
            $out[] = [
                'execution_id' => $row->id,
                'rule_id' => $row->rule_id,
                'rule_name' => $row->name,
                'executed_at' => $row->executed_at,
                'action_outcomes' => $outcomes,
            ];
        }

        return $out;
    }
}
