<?php

declare(strict_types=1);

namespace App\Jobs\Conversations;

use App\Domain\Conversations\Inbound\BatchTailHint;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The 2-second half of EC-01's debounce: dispatched 2 s behind every
 * non-quarantined inbound, it confirms the batch-tail hint only if nothing newer
 * arrived for the conversation in the meantime. Ten messages in a burst dispatch
 * ten of these; exactly one — the last — still matches the candidate and sets the
 * hint Doc 09's turn decision reads.
 *
 * tries = 1: a lost confirm costs one extra AI turn decision (Doc 09 debounces on
 * its own side too); retrying a stale confirm could only set an OUTDATED hint.
 */
final class ConfirmBatchTail implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 1;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {
        $this->captureTenant();
    }

    public function handle(BatchTailHint $batchTail): void
    {
        $batchTail->confirm($this->conversationId, $this->messageId);
    }
}
