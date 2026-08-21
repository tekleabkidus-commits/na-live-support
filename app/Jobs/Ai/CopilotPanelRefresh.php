<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\Concurrency\TenantSemaphore;
use App\Domain\Ai\Copilot\CopilotPanel;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FR-14's panel refresh as a queued job on the `ai` family (CLAUDE.md §3:
 * provider calls never run inline in a request). Debounced by the panel's
 * 10 s TTL lock at dispatch; FR-03's per-tenant semaphore at execution —
 * when the tenant's slots are full the job RELEASES itself back (no
 * provider call, no slot leak), the panel keeps saying "refreshing".
 */
final class CopilotPanelRefresh implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $userId,
    ) {
        $this->captureTenant();
        $this->onQueue('ai');
    }

    public function handle(CopilotPanel $panel, TenantSemaphore $semaphore): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        $user = User::query()->find($this->userId);
        if ($conversation === null || $user === null) {
            return;
        }

        if (! $semaphore->acquire()) {
            $this->release(2);

            return;
        }

        try {
            $panel->refresh($user, $conversation);
        } finally {
            $semaphore->release();
        }
    }
}
