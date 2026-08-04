<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * The two outcomes an adapter send can report (Doc 04 FR-08). The full per-message
 * delivery state machine (queued → sending → sent → delivered / failed…, FR-13) is the
 * CORE's; adapters only ever answer sent-or-failed per attempt.
 */
enum SendState: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
