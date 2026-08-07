<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

use RuntimeException;

/**
 * FR-15: "assignee must hold view scope over the ticket (Doc 03 intersection) —
 * violations rejected assignee_out_of_scope."
 *
 * WHY THE RULE EXISTS AT ALL. Assigning work to someone who cannot see it produces a
 * ticket that is nobody's in practice while looking owned on every report — the
 * "answered but unassigned" limbo BR-03 fights, arriving through the other door. It is
 * also SEC-01's family: moving work must not smuggle visibility, and it must not
 * strand it either.
 *
 * The check is on the TARGET's scope, never the assigner's.
 */
final class AssigneeOutOfScope extends RuntimeException
{
    public const CODE = 'assignee_out_of_scope';

    public function __construct(public readonly string $userId)
    {
        parent::__construct(sprintf(
            'assignee_out_of_scope: user %s does not hold view scope over this ticket (FR-15).',
            $userId,
        ));
    }
}
