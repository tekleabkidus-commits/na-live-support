<?php

declare(strict_types=1);

namespace App\Domain\Kb\Editor;

use DomainException;

/** Doc 10 FR-05: another editor holds the variant ("Hanna is editing"); takeover is the door. */
final class LockHeld extends DomainException
{
    public function __construct(public readonly string $holderId)
    {
        parent::__construct('variant is being edited by another user');
    }
}
