<?php

declare(strict_types=1);

namespace App\Domain\Ai\DecisionLog;

use App\Jobs\Ai\PersistAiDecisions;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FR-20/T-08/NFR-05: the decision log's ASYNC BATCHED writer.
 *
 * record() STAGES (an in-memory append — the <=3 ms budget's whole cost);
 * flush() dispatches ONE ai-background job carrying the staged rows
 * (afterCommit by bus default, C-06), so task completion never waits on an
 * insert. One task = one record; a multi-task turn (classify + chatbot)
 * batches naturally per flush.
 *
 * THE SAMPLED EVENT (FR-21, flag 1 of the countersigned amendment):
 * ai.decision.logged reaches the bus for a SAMPLED subset — the rate is the
 * ai.decisions.event_sample_permille PLATFORM setting — with the Q7 payload
 * (identifiers + categories, never text); FULL fidelity is the table, not
 * the stream. Emission rides flush() on the caller's own transaction (the
 * outbox makes it transactional); the async row insert may land moments
 * later — a consumer joining on decision_id re-reads and retries (C-09).
 */
final class DecisionWriter
{
    /** @var list<DecisionRecord> */
    private array $staged = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly SettingsRepository $settings,
    ) {}

    public function record(DecisionRecord $record): void
    {
        $this->staged[] = $record;
    }

    public function flush(): void
    {
        if ($this->staged === []) {
            return;
        }

        $rows = $this->staged;
        $this->staged = [];

        $tenantId = $this->context->tenantId();

        // Self-transactional: the sampled event needs the outbox's transaction
        // (Doc 01 T-03); inside a caller's transaction this is a savepoint and
        // the event commits with the caller's write, outside one it is its own
        // short transaction. Either way the dispatch is afterCommit (C-06).
        DB::transaction(function () use ($rows, $tenantId): void {
            foreach ($rows as $record) {
                if ($this->sampled()) {
                    $this->events->publish('ai.decision.logged', [
                        'tenant_id' => $tenantId,
                        'decision_id' => $record->id,
                        'surface' => $record->surface,
                        'task' => $record->task,
                        'action' => $record->action ?? 'none',
                        'error_category' => $record->errorCategory ?? 'none',
                    ]);
                }
            }

            PersistAiDecisions::dispatch($tenantId, array_map(
                static fn (DecisionRecord $record): array => PersistAiDecisions::rowFor($record),
                $rows,
            ));
        });
    }

    private function sampled(): bool
    {
        $raw = $this->settings->get('ai.decisions.event_sample_permille');
        $permille = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 10);

        if ($permille <= 0) {
            return false;
        }
        if ($permille >= 1000) {
            return true;
        }

        return random_int(1, 1000) <= $permille;
    }
}
