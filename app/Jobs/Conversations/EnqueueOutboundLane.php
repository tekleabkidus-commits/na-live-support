<?php

declare(strict_types=1);

namespace App\Jobs\Conversations;

use App\Domain\Conversations\Outbound\OutboundLanes;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FR-12's queue leg: after the compose transaction commits (C-06 afterCommit),
 * push the message id onto its conversation's FIFO lane. This is the ONLY lane
 * producer — ordering is compose-commit order because this job runs on the
 * channel's outbound family in dispatch order.
 *
 * Idempotent enough for at-least-once (C-09): a duplicate push would put the
 * same id in the lane twice, and the worker pops already-terminal rows without
 * sending — a wasted cycle, never a double send.
 */
final class EnqueueOutboundLane implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {
        $this->captureTenant();
    }

    public function handle(OutboundLanes $lanes): void
    {
        $lanes->push($this->conversationId, $this->messageId);
    }
}
