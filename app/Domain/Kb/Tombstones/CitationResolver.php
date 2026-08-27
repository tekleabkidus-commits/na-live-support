<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

/**
 * Doc 10 FR-16 / T-06 / EC-06 / EC-07 — the resolver every citation URL goes
 * through (S10 step 1: the CONTRACT, fixture-fed by chunk state; step 7 binds
 * versions, slugs and slug history behind the same door). Never throws for a
 * well-formed ref; never answers with a 404-shaped result.
 */
interface CitationResolver
{
    public function resolve(string $articleRef): CitationState;
}
