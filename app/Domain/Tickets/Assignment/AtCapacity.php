<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

use RuntimeException;

/**
 * Doc 11 FR-05/EC-01/EC-03: the assignee is at capacity at COMMIT time. Not an error a
 * user sees — the dispatcher's "next candidate" signal (T-03), raised inside the
 * assignment transaction so the ticket row is never written for an over-capacity agent.
 */
final class AtCapacity extends RuntimeException
{
    public const CODE = 'at_capacity';

    public function __construct(public readonly string $userId, public readonly int $capacity, public readonly int $openAssigned)
    {
        parent::__construct(sprintf('at_capacity: user %s holds %d of %d (Doc 11 FR-05).', $userId, $openAssigned, $capacity));
    }
}
