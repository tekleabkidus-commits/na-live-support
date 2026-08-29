<?php

declare(strict_types=1);

namespace App\Domain\Kb\Feedback;

use RuntimeException;

/** SEC-05/EC-10: rate-limited (429) or over the length cap (422) — the reason names which. */
final class FeedbackRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
