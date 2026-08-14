<?php

declare(strict_types=1);

namespace App\Domain\Customers\Merge;

use Exception;

/**
 * EC-12's refusal: the combined open linked_work would exceed Doc 06's cap
 * of 3 — blocked AT THE CONFIRM STEP with the EXACT tickets listed
 * ("resolve or transfer one first" — D13's frame renders these ids).
 */
final class MergeBlocked extends Exception
{
    /** @param list<string> $openLinkedWorkTicketIds */
    public function __construct(public readonly array $openLinkedWorkTicketIds)
    {
        parent::__construct(sprintf(
            'this merge would put %d open linked-work tickets on one customer — the cap is 3 (Doc 06 FR-21; EC-12)',
            count($openLinkedWorkTicketIds),
        ));
    }
}
