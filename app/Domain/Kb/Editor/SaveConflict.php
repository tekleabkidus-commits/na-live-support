<?php

declare(strict_types=1);

namespace App\Domain\Kb\Editor;

use DomainException;

/** Doc 10 FS-05: optimistic save lost the race — the merge-conflict UI is the caller's. */
final class SaveConflict extends DomainException
{
    public function __construct(public readonly ?string $currentSavedAt)
    {
        parent::__construct('the working copy changed since your last save');
    }
}
