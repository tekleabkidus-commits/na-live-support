<?php

declare(strict_types=1);

namespace App\Domain\Routing\Eligibility;

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Routing\Capacity\CapacityResolver;

/**
 * Doc 11 FR-05 — Routable = status Online (Doc 07 FR-28's contract, heartbeat-loss
 * materialized on read) ∧ open-assigned count < capacity ∧ effective member of the queue
 * (FR-04, config errors excluded). Away/Offline/heartbeat-lost agents are NEVER assigned
 * (BR-05: the router never assigns to Away agents to make numbers look better). Hours
 * never touch eligibility (FR-14/EC-09).
 *
 * This is the read-side answer the strategies (FR-09..11) work over; the commit-time
 * capacity check (RoutingCapacity, FOR UPDATE) is what makes the race safe (EC-01/EC-03).
 */
final class RoutableAgents
{
    public function __construct(
        private readonly EffectiveMembers $members,
        private readonly AgentStatus $status,
        private readonly CapacityResolver $capacity,
    ) {}

    /**
     * @return list<array{user_id: string, capacity: int, load: int, free: int}> sorted by user_id (the documented tiebreak floor)
     */
    public function forQueue(string $queueId): array
    {
        $out = [];
        foreach ($this->members->eligible($queueId) as $userId) {
            if ($this->status->current($userId)['status'] !== 'online') {
                continue;
            }
            $load = $this->capacity->loadFor($userId, $queueId);
            if ($load['free'] <= 0) {
                continue;
            }
            $out[] = ['user_id' => $userId] + $load;
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['user_id'], $b['user_id']));

        return $out;
    }

    /**
     * The FR-05 breakdown the J14 banner shows: members · online · at capacity · eligible.
     *
     * @return array{members: int, online: int, at_capacity: int, eligible: int}
     */
    public function breakdown(string $queueId): array
    {
        $members = $this->members->eligible($queueId);
        $online = 0;
        $atCapacity = 0;
        foreach ($members as $userId) {
            if ($this->status->current($userId)['status'] !== 'online') {
                continue;
            }
            $online++;
            if ($this->capacity->loadFor($userId, $queueId)['free'] <= 0) {
                $atCapacity++;
            }
        }

        return ['members' => count($members), 'online' => $online, 'at_capacity' => $atCapacity, 'eligible' => $online - $atCapacity];
    }
}
