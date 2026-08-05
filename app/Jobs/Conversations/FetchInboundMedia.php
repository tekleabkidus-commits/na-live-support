<?php

declare(strict_types=1);

namespace App\Jobs\Conversations;

use App\Domain\Conversations\Media\MediaOutcome;
use App\Domain\Conversations\Media\MediaPipeline;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Support\Tenancy\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FR-10 step 5: one job per media item, on the `media` family — queue isolation
 * IS FS-03's text-pipeline protection (a media backlog cannot slow a text
 * message, they never share a queue).
 *
 * tries = 1 on purpose: retry state lives in the media ITEM (scan_attempts,
 * first_attempt_at), and a Held outcome re-dispatches with a delay — so the
 * EC-11 15-minute clock is data that survives worker restarts, not queue
 * bookkeeping that dies with a process.
 */
final class FetchInboundMedia implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 1;

    private const RETRY_DELAY_SECONDS = 60;

    public function __construct(
        public readonly string $messageId,
        public readonly int $index,
    ) {
        $this->captureTenant();
    }

    public function handle(MediaPipeline $pipeline, MessageRepository $messages): void
    {
        $message = $messages->findById($this->messageId);

        if ($message === null) {
            return; // row gone from this tenant's view — nothing to scan
        }

        $outcome = $pipeline->process($message, $this->index, CarbonImmutable::now('UTC'));

        if ($outcome === MediaOutcome::Held) {
            self::dispatch($this->messageId, $this->index)
                ->onQueue('media')
                ->delay(self::RETRY_DELAY_SECONDS);
        }
    }
}
