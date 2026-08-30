<?php

declare(strict_types=1);

namespace App\Domain\Routing\Capacity;

use App\Domain\Tickets\Assignment\AssignmentCapacity;
use App\Domain\Tickets\Assignment\AtCapacity;
use App\Domain\Tickets\Assignment\RoutingCommit;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-05 / FR-06 / EC-01 / EC-03 / AC-03 — capacity at COMMIT time, serialized by
 * the database (S11 STOP-A packet; Doc 11's implementation of Doc 06's interface).
 *
 * Inside the caller's transaction (AssignTicket::assignBySystem): the agent's
 * agent_assignment_stats row is created-if-missing and locked with SELECT … FOR UPDATE,
 * the effective count is computed under the lock (FR-06: open-family assigned minus
 * pending_customer unless routing.capacity.count_pending), the capacity is the per-user
 * override (FR-06 routing.capacity.user, US-07) else the queue's rule (FR-03:
 * capacity_per_agent or routing.capacity.default — resolved by the caller into the
 * RoutingCommit), and either the count is incremented here — the reservation — or
 * AtCapacity is thrown and nothing was written. Two dispatchers racing for one agent
 * wait on the same row lock; the second reads the first's increment. That is EC-01's
 * "the agent's capacity check at commit serializes" and AC-03's zero over-capacity.
 *
 * last_assigned_at is the database's (FLAG F trigger) — never named here (R-19a).
 * The counters are rebuildable from tickets (routing:rebuild, FS-01); the step-4
 * durable consumers keep them current across status changes and unassignments.
 */
final class RoutingCapacity implements AssignmentCapacity
{
    public function __construct(private readonly TenantContext $context) {}

    public function reserveOrThrow(User $assignee, RoutingCommit $commit): void
    {
        $tenantId = $this->context->tenantId();
        $userId = $assignee->identifier();

        /** @var object{open_assigned: int|string, pending_customer_assigned: int|string}|null $row */
        $row = DB::selectOne(
            'SELECT open_assigned, pending_customer_assigned FROM agent_assignment_stats WHERE tenant_id = ? AND user_id = ? FOR UPDATE',
            [$tenantId, $userId],
        );

        if ($row === null) {
            DB::table('agent_assignment_stats')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'open_assigned' => 0,
                'pending_customer_assigned' => 0,
            ]);
            /** @var object{open_assigned: int|string, pending_customer_assigned: int|string} $row */
            $row = DB::selectOne(
                'SELECT open_assigned, pending_customer_assigned FROM agent_assignment_stats WHERE tenant_id = ? AND user_id = ? FOR UPDATE',
                [$tenantId, $userId],
            );
        }

        $open = (int) $row->open_assigned;
        $pending = (int) $row->pending_customer_assigned;
        $effective = $commit->countPending ? $open : max(0, $open - $pending);

        /** @var int|string|null $override */
        $override = DB::table('user_routing_capacity')->where('user_id', $userId)->value('capacity');
        $capacity = $override !== null ? (int) $override : $commit->queueCapacity;

        if ($effective >= $capacity) {
            throw new AtCapacity($userId, $capacity, $effective);
        }

        // The reservation: the increment rides the caller's transaction and rolls back with it.
        DB::table('agent_assignment_stats')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update(['open_assigned' => $open + 1, 'updated_at' => DB::raw('clock_timestamp()')]);
    }
}
