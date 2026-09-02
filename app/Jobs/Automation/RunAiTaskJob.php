<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Domain\Automation\Ai\AutomationAiTasks;
use App\Models\Conversation;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Doc 12 FR-03 run AI task — the QUEUED model call (CLAUDE.md §3: never
 * inline), on the ai-background family (nobody is waiting at a composer).
 * The note on the timeline is the product; the execution row's outcome
 * stays `queued` and this job's result goes to the structured log (FR-06's
 * detail names the task; the note itself is the visible proof). tries=1:
 * a rule-driven summary is never replayed behind the operator's back — the
 * next trigger produces the next one.
 */
final class RunAiTaskJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 1;

    public function __construct(
        public readonly string $executionId,
        public readonly string $task,
        public readonly string $conversationId,
    ) {
        $this->captureTenant();
    }

    public function handle(AutomationAiTasks $tasks): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        if (! $conversation instanceof Conversation) {
            return;
        }
        try {
            $result = match ($this->task) {
                'summarize_thread' => $tasks->summarizeToNote($conversation),
                'translate_last_message' => $tasks->translateLastMessageToNote($conversation),
                'classify_refresh' => $tasks->classifyRefresh($conversation),
                default => throw new \InvalidArgumentException('unbuilt automation AI task '.$this->task),
            };
            Log::info('automation.ai_task.done', ['execution_id' => $this->executionId, 'task' => $this->task] + $result);
        } catch (Throwable $e) {
            Log::warning('automation.ai_task.failed', ['execution_id' => $this->executionId, 'task' => $this->task, 'error' => $e->getMessage()]);
        }
    }
}
