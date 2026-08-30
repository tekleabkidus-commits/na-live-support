<?php

declare(strict_types=1);

namespace App\Domain\Routing\Capacity;

use App\Domain\Tickets\Assignment\RoutingCommit;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-03 / FR-06 — the capacity rule in force for a queue, and an agent's free slots.
 * queue.capacity_per_agent ?? routing.capacity.default; count_pending per the setting;
 * the per-user override (user_routing_capacity) outranks both (US-07). The COMMIT-time
 * check is RoutingCapacity (FOR UPDATE); this resolver answers the read-side question the
 * dispatcher asks before it tries (FR-05 "open-assigned count < capacity").
 */
final class CapacityResolver
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function queueCapacity(string $queueId): int
    {
        $own = DB::table('queues')->where('id', $queueId)->value('capacity_per_agent');
        if (is_numeric($own) && (int) $own > 0) {
            return (int) $own;
        }
        $default = $this->settings->get('routing.capacity.default');

        return is_int($default) && $default > 0 ? $default : 5;
    }

    public function countPending(): bool
    {
        return (bool) $this->settings->get('routing.capacity.count_pending');
    }

    public function commitFor(string $decisionId, string $queueId): RoutingCommit
    {
        return new RoutingCommit($decisionId, $queueId, $this->queueCapacity($queueId), $this->countPending());
    }

    /**
     * The agent's effective capacity (override outranks the queue rule) and current load.
     *
     * @return array{capacity: int, load: int, free: int}
     */
    public function loadFor(string $userId, string $queueId): array
    {
        $override = DB::table('user_routing_capacity')->where('user_id', $userId)->value('capacity');
        $capacity = is_numeric($override) && (int) $override > 0 ? (int) $override : $this->queueCapacity($queueId);
        /** @var object{open_assigned: int|string, pending_customer_assigned: int|string}|null $row */
        $row = DB::table('agent_assignment_stats')->where('user_id', $userId)->first(['open_assigned', 'pending_customer_assigned']);
        $open = $row === null ? 0 : (int) $row->open_assigned;
        $pending = $row === null ? 0 : (int) $row->pending_customer_assigned;
        $load = $this->countPending() ? $open : max(0, $open - $pending);

        return ['capacity' => $capacity, 'load' => $load, 'free' => max(0, $capacity - $load)];
    }
}
