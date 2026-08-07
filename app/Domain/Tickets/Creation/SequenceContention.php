<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

use RuntimeException;

/**
 * FS-03: "Sequence contention spike (mass API creates) → short lock waits within
 * NFR-05; beyond threshold, creates receive backpressure 429 — never duplicate
 * numbers."
 *
 * The last clause is the one that matters and it is the reason this is an exception
 * rather than a retry loop. `tenant_sequences` serialises allocators on a row lock, so
 * a contention spike shows up as WAITING, never as two callers computing the same
 * number. The failure mode to avoid is therefore not duplication — the lock already
 * makes that impossible — it is a pile-up of connections all blocked on one row while
 * the client sits there. So the wait is BOUNDED (`SET LOCAL lock_timeout`) and the
 * caller is told to come back, which is what 429 means.
 */
final class SequenceContention extends RuntimeException
{
    public const CODE = 'sequence_contention';

    public function __construct(public readonly int $waitedMs)
    {
        parent::__construct(sprintf(
            'sequence_contention: the ticket-number counter stayed locked for %d ms; retry (FS-03). '
            .'No number was allocated and none was duplicated.',
            $waitedMs,
        ));
    }
}
