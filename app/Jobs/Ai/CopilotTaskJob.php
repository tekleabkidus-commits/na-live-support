<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\Concurrency\TenantSemaphore;
use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Copilot\CopilotStreamRelay;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

/**
 * FR-14/FR-15's generation as a queued job on the `ai` family, PRIORITY
 * LANE (FR-03: an agent is waiting at the composer — the same lane the
 * chatbot turn rides; digests and pre-scores ride ai-background). The
 * result streams to the requesting agent's user channel (T-04) and ends
 * in ONE ai.copilot.card frame carrying the artifact id; nothing else
 * leaves this job — there is no send here and no way to add one without
 * CopilotNeverAutoSendTest going red (BR-01).
 *
 * @phpstan-type Params array{text?: string, target_language?: string}
 */
final class CopilotTaskJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public const ACTIONS = ['draft', 'improve', 'translate', 'summarize'];

    public int $tries = 1; // an agent-facing draft is never replayed behind their back; they press the button again

    /** @param array{text?: string, target_language?: string} $params */
    public function __construct(
        public readonly string $requestId,
        public readonly string $action,
        public readonly string $conversationId,
        public readonly string $userId,
        public readonly array $params = [],
    ) {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(sprintf('unknown Copilot action "%s"', $action));
        }
        $this->captureTenant();
        $this->onQueue('ai');
    }

    public function handle(ComposerActions $actions, CopilotStreamRelay $relay, TenantSemaphore $semaphore): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        if ($conversation === null || User::query()->find($this->userId) === null) {
            return;
        }

        if (! $semaphore->acquire()) {
            $relay->card($this->userId, $this->requestId, ['state' => 'busy', 'kind' => $this->action, 'notices' => ['ai.copilot.notice.busy']]);

            return;
        }

        try {
            $stream = $relay->open($this->userId, $this->requestId);
            $card = match ($this->action) {
                'draft' => $actions->draft($this->userId, $conversation, $stream),
                'improve' => $actions->improve($this->userId, $conversation, (string) ($this->params['text'] ?? ''), $stream),
                'translate' => $actions->translate($this->userId, $conversation, (string) ($this->params['text'] ?? ''), $this->params['target_language'] ?? null, $stream),
                'summarize' => $actions->summarize($this->userId, $conversation, $stream),
                default => throw new InvalidArgumentException(sprintf('unknown Copilot action "%s"', $this->action)),
            };
            $relay->card($this->userId, $this->requestId, $card);
        } finally {
            $semaphore->release();
        }
    }
}
