<?php

declare(strict_types=1);

namespace App\Domain\Kb\Workflow;

use DomainException;

/** Doc 10 FR-07: a transition the matrix does not allow from the current state (or a gate refused it — AC-10 alts, BR-06 flags). */
final class TransitionRefused extends DomainException
{
    /** @param array<string, mixed> $facts */
    public function __construct(string $message, public readonly array $facts = [])
    {
        parent::__construct($message);
    }
}
