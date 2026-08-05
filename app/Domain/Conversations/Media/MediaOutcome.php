<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

/**
 * What one pipeline pass decided: Done means the item reached a terminal
 * scan_status (clean, infected, failed, rejected, quarantined_pending); Held
 * means try again later (EC-11's scanning hold, FS-02's fetch retry) — the job
 * re-dispatches itself with a delay, and the CLOCK lives in the item's data
 * (first_attempt_at), never in worker memory.
 */
enum MediaOutcome
{
    case Done;
    case Held;
}
