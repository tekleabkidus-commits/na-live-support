<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

/**
 * Doc 11 FR-09..FR-11 — the strategy port. Given the routable set (FR-05, sorted by user id —
 * the documented tiebreak floor) and the ticket's attributes, pick the ORDERED candidate list
 * the dispatcher tries in turn (T-03: race → next candidate). Step 5 ships FirstEligible;
 * step 6 ships RoundRobin, LoadBased and SkillBased behind the same port.
 */
interface AssignmentStrategy
{
    public function name(): string;

    /**
     * @param  list<array{user_id: string, capacity: int, load: int, free: int}>  $routable
     * @param  array<string, mixed>  $ticket  language, department_id, tags, priority
     * @return array{candidates: list<string>, note: string}
     */
    public function order(string $queueId, array $routable, array $ticket): array;
}
