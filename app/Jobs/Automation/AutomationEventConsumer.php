<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Domain\Automation\Engine\Evaluator;
use App\Domain\Automation\Engine\TriggerMap;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-01/FR-04 (ADR 0011) — THE durable automation consumer: one entry in
 * the dispatch map per trigger event; carries ONLY the event id; re-reads the
 * event and its world under its own tenant context; IDEMPOTENT on event_id by
 * the executions table's (rule, event) unique — a redelivery inserts nothing
 * and dispatches nothing (C-09 as database physics, the drill's assertion).
 *
 * Evaluation is synchronous INSIDE this job (cheap, indexed — NFR-01); the
 * matched rules' action chains queue separately on `automation` (FS-01
 * isolation: an action backlog never delays evaluation of the next event).
 */
final class AutomationEventConsumer extends OutboxConsumer
{
    public function handle(Evaluator $evaluator): void
    {
        $name = DB::table('outbox')->where('event_id', $this->eventId)->value('name');
        if (! is_string($name)) {
            return;
        }
        foreach (TriggerMap::triggersFor($name) as $trigger) {
            $evaluator->evaluate($this->eventId, $trigger);
        }
    }

    protected function queueFamily(): string
    {
        return 'automation';
    }
}
