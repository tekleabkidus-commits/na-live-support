<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use DateTimeImmutable;
use RuntimeException;

/**
 * The transition was legal but the actor may not make it (Doc 03).
 *
 * `grantedBy` and `expiresAt` are carried because of Doc 03 EC-02: a permission that
 * expired mid-action must refuse with an explanation that names WHO granted it and
 * WHEN it lapsed — "your temporary access expired" with attribution, rather than a bare
 * denial that looks like a bug to the person holding the ticket. They are populated
 * only when the verdict came from an override; a plain role denial carries neither.
 */
final class TransitionNotPermitted extends RuntimeException
{
    public const CODE = 'transition_not_permitted';

    public function __construct(
        public readonly string $permission,
        public readonly ?string $grantedBy = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
        parent::__construct(sprintf('transition_not_permitted: %s is required.', $permission));
    }
}
