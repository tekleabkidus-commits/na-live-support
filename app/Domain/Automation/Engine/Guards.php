<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-04 / BR-05 / T-03 / EC-12 — the guard caps are PLATFORM PHYSICS.
 *
 * The three limits are CODE CONSTANTS, deliberately absent from the settings
 * registry (BR-05: operators tune WITHIN them — per-rule cooldown 0..86400 by
 * column CHECK — never above them; a cap configurable above the platform limit
 * is not a cap). AutomationGuardCapsTest sweeps the registry to prove no key
 * names them; sabotage (a) removes a cap and the loop/blast property goes red.
 */
final class Guards
{
    /** FR-04: at most this many rules FIRE per event. */
    public const MAX_RULES_PER_EVENT = 10;

    /** FR-04: at most this many total actions run per event. */
    public const MAX_ACTIONS_PER_EVENT = 20;

    /** FR-04/T-03: an action-caused event chain stops at this depth. */
    public const MAX_CHAIN_DEPTH = 3;

    /**
     * EC-12: the same-rule-same-TICKET cooldown — keyed on (rule, ticket), so a
     * second ticket evaluates fresh. Truth is the executions table in DB time
     * (C-11); a matched execution younger than the rule's cooldown suppresses.
     */
    public function inCooldown(string $ruleId, ?string $ticketId, int $cooldownSeconds): bool
    {
        if ($ticketId === null || $cooldownSeconds <= 0) {
            return false;
        }

        /** @var object{hot: bool}|null $row */
        $row = DB::selectOne(
            "SELECT true AS hot FROM automation_rule_executions
             WHERE rule_id = ? AND matched AND context_ref->>'ticket_id' = ?
               AND executed_at > clock_timestamp() - make_interval(secs => ?)
             LIMIT 1",
            [$ruleId, $ticketId, $cooldownSeconds],
        );

        return $row !== null;
    }
}
