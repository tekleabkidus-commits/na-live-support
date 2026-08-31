<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

/**
 * Step 5's interim strategy (the plan's engine-before-strategies order): the routable set
 * in user-id order — FR-10's documented tiebreak FLOOR, so the record is deterministic
 * from day one. Replaced behind the port at step 6 (RR / load / skill); never used once
 * a queue's strategy resolves.
 */
final class FirstEligible implements AssignmentStrategy
{
    public function name(): string
    {
        return 'first_eligible';
    }

    public function order(string $queueId, array $routable, array $ticket): array
    {
        return ['candidates' => array_column($routable, 'user_id'), 'note' => 'user-id order (the documented tiebreak floor)', 'note_code' => 'strategy.first', 'note_slots' => []];
    }
}
