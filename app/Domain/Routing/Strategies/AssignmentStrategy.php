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
     * @return array{candidates: list<string>, note: string, facts?: array<string, mixed>, note_code?: string, note_slots?: array<string, mixed>, then_code?: string, then_slots?: array<string, mixed>} facts = AGENT-ID step-input keys (SEC-02: elided per role by the record reader; NEVER inside the prose note). note_code/note_slots (S11 step 14, §14): the ICU sentence variant for the note — slots are COUNTS and language codes only, never agent ids; then_code/then_slots = the chained second sentence (skill → load).
     */
    public function order(string $queueId, array $routable, array $ticket): array;
}
