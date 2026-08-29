<?php

declare(strict_types=1);

namespace App\Domain\Kb\Media;

use RuntimeException;

/** SEC-02: an upload the pipeline refuses (infected) — the reason names which; nothing was stored. */
final class MediaRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
