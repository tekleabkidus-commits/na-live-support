<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Domain\Automation\Actions\ActionRegistry;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 12 T-02 / BR-02 / AC-01 / EC-01 — ONE ordered chain per matched rule:
 * per-action try/log/continue. A failed action (an illegal transition refused
 * by Doc 06's service, a missing capability, an unknown arm) records its
 * honest outcome and THE CHAIN CONTINUES — automation gains no bypass powers
 * and no fragility either. Outcomes append onto the execution row
 * (action_outcomes), which FR-06's detail view renders.
 *
 * `automationDepth` is the NEXT depth: any event an arm publishes carries it
 * as envelope metadata (T-03) so a rule-caused cascade self-limits at 3.
 * EC-01: each arm reports superseded_state=true when it acted on state an
 * earlier action already changed (the arm compares its read against the
 * chain's declared intent — honesty, not refusal).
 */
final class ActionChainJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, mixed>  $basis  EC-01: the facts the rule matched on
     */
    public function __construct(
        public readonly string $executionId,
        public readonly array $actions,
        public readonly int $automationDepth,
        public readonly array $basis = [],
    ) {
        $this->captureTenant();
    }

    public function handle(ActionRegistry $registry): void
    {
        $outcomes = [];
        foreach ($this->actions as $index => $action) {
            $type = is_string($action['type'] ?? null) ? $action['type'] : 'unknown';
            try {
                $outcomes[$index] = $registry->execute($type, $action, $this->executionId, $this->automationDepth, $this->basis);
            } catch (\Throwable $e) {
                // Per-action isolation (AC-01): log, continue. Never rethrow — a
                // chain that dies mid-way would retry the ALREADY-RUN head.
                Log::warning('automation action failed — chain continues (BR-02/AC-01)', [
                    'execution_id' => $this->executionId, 'action' => $type, 'error' => $e->getMessage(),
                ]);
                $outcomes[$index] = ['action' => $type, 'outcome' => 'failed', 'reason' => $e->getMessage()];
            }
        }
        DB::table('automation_rule_executions')
            ->where('id', $this->executionId)
            ->update(['action_outcomes' => json_encode(array_values($outcomes), JSON_THROW_ON_ERROR)]);
    }
}
