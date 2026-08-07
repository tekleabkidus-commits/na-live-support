<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

use RuntimeException;

/**
 * FR-16's claim race: "first commit wins; loser receives already_claimed {by} and the
 * UI updates in place (inline avatar swap, no error-modal storm)."
 *
 * THE `by` IS THE WHOLE POINT and is why this is a typed exception rather than a bare
 * false. US-01 asks that an agent "know instantly if a colleague beat me to it" — a
 * refusal that cannot name the colleague forces the UI into an apology instead of an
 * avatar swap, which is exactly the error-modal storm FR-16 names.
 */
final class AlreadyClaimed extends RuntimeException
{
    public const CODE = 'already_claimed';

    public function __construct(public readonly string $ticketId, public readonly string $by)
    {
        parent::__construct(sprintf('already_claimed: ticket %s was claimed by %s first.', $ticketId, $by));
    }
}
