<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Jobs\Automation\RunAiTaskJob;
use App\Models\Ticket;

/**
 * Doc 12 FR-03 run AI task — CATALOG-BOUND to the document's three names
 * (summarize→note, classify-refresh, translate-last-message→note); any
 * other name — including a real Doc 09 catalog task like chatbot_turn —
 * refuses `unknown_ai_task` (a rule cannot reach the customer-visible
 * tasks). The model call is NEVER inline (CLAUDE.md §3): the arm QUEUES
 * RunAiTaskJob on the ai-background family and answers `queued`; the note
 * on the ticket timeline is the product. classify_refresh rides P-CLS's
 * classify-ONLY door (InboundClassification::classifyOnly, countersigned at
 * the close-out recount): no route executes; the FR-02 ai.* facts refresh
 * from a REAL decision row.
 */
final class RunAiTaskAction implements AutomationAction
{
    use ArmSupport;

    public const TASKS = ['summarize_thread', 'translate_last_message', 'classify_refresh'];

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $task = is_string($action['task'] ?? null) ? $action['task'] : '';
        if (! in_array($task, self::TASKS, true)) {
            return ['action' => 'run_ai_task', 'outcome' => 'failed', 'reason' => 'unknown_ai_task'];
        }
        $ticket = $this->ticketOf($executionId);
        if (! $ticket instanceof Ticket) {
            return ['action' => 'run_ai_task', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        $conversationId = $ticket->getAttribute('conversation_id');
        if (! is_string($conversationId)) {
            return ['action' => 'run_ai_task', 'outcome' => 'failed', 'reason' => 'no_conversation'];
        }
        RunAiTaskJob::dispatch($executionId, $task, $conversationId)->onQueue('ai-background');

        return ['action' => 'run_ai_task', 'outcome' => 'queued', 'task' => $task];
    }
}
