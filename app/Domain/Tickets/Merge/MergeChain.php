<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Merge;

use App\Models\Ticket;
use LogicException;

/**
 * EC-03's transitive resolution: "merged_into pointers resolve transitively; banners
 * link to the final survivor."
 *
 * The chain is acyclic BY CONSTRUCTION — MergeTickets refuses any merge whose target
 * chain already contains a source (A-13) — so the walk terminates. The hop bound is a
 * defensive wall, not a policy: if it ever fires, an illegal cycle reached the
 * database past the guard, which is FS-05's territory (an invariant breach to page
 * on), not something to resolve quietly by truncating the walk.
 */
final class MergeChain
{
    private const MAX_HOPS = 32;

    /** The final survivor a banner links to (EC-03). The ticket itself if unmerged. */
    public function survivor(Ticket $ticket): Ticket
    {
        $current = $ticket;

        foreach (range(1, self::MAX_HOPS) as $hop) {
            if ($current->merged_into === null) {
                return $current;
            }

            /** @var Ticket $current */
            $current = Ticket::query()->findOrFail($current->merged_into);
        }

        throw new LogicException(sprintf(
            'merged_into chain from ticket %s exceeds %d hops — an illegal cycle reached the database (EC-03/FS-05).',
            $ticket->identifier(),
            self::MAX_HOPS,
        ));
    }

    /**
     * Every ticket id on the chain from here upward, INCLUDING this ticket's own id —
     * the merge-time cycle guard asks "is any source on the target's chain?" and the
     * target itself being a source is the smallest cycle of all.
     *
     * @return list<string>
     */
    public function chainIds(Ticket $ticket): array
    {
        $ids = [$ticket->identifier()];
        $current = $ticket;

        foreach (range(1, self::MAX_HOPS) as $hop) {
            if ($current->merged_into === null) {
                return $ids;
            }

            /** @var Ticket $current */
            $current = Ticket::query()->findOrFail($current->merged_into);
            $ids[] = $current->identifier();
        }

        throw new LogicException(sprintf(
            'merged_into chain from ticket %s exceeds %d hops — an illegal cycle reached the database (EC-03/FS-05).',
            $ticket->identifier(),
            self::MAX_HOPS,
        ));
    }
}
