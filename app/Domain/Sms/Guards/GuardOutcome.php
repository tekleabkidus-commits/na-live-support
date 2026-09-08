<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/** Doc 14 T-06: proceed | hold (FR-16's queued(held), hold-all-sends) | skip{reason}. */
enum GuardOutcome: string
{
    case Proceed = 'proceed';
    case Hold = 'hold';
    case Skip = 'skip';
}
