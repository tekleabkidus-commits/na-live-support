<?php

declare(strict_types=1);

namespace App\Domain\Routing\Capacity;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-05 / FR-06 / FS-01 — agent_assignment_stats maintained FROM TRUTH.
 *
 * RECOMPUTE-FROM-TRUTH (the Doc 07/08 projector pattern): an event names WHICH agents
 * to recompute; every value is re-derived from `tickets` — open_assigned = the agent's
 * open-family assigned tickets, pending_customer_assigned = those in pending_customer.
 * A redelivery recomputes the same truth into the same columns (C-09 by construction).
 * last_assigned_at is the FLAG-F trigger's — recompute never names it, and an UPDATE
 * that does not increase open_assigned leaves it untouched.
 *
 * rebuild() (FS-01, `routing:rebuild`) recomputes every agent who holds or held work.
 */
final class AssignmentStats
{
    public const OPEN_FAMILY = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];

    public function __construct(private readonly TenantContext $context) {}

    /** @return array{open_assigned: int, pending_customer_assigned: int} */
    public function recompute(string $userId): array
    {
        /** @var object{open: int|string, pending: int|string} $truth */
        $truth = DB::selectOne(
            "SELECT count(*) FILTER (WHERE status IN ('new','open','pending_customer','pending_internal','escalated')) AS open,
                    count(*) FILTER (WHERE status = 'pending_customer') AS pending
               FROM tickets WHERE assignee_id = ?",
            [$userId],
        );
        $open = (int) $truth->open;
        $pending = (int) $truth->pending;

        $written = DB::table('agent_assignment_stats')->where('user_id', $userId)
            ->update(['open_assigned' => $open, 'pending_customer_assigned' => $pending, 'updated_at' => DB::raw('clock_timestamp()')]);
        if ($written === 0) {
            DB::table('agent_assignment_stats')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(), 'user_id' => $userId,
                'open_assigned' => $open, 'pending_customer_assigned' => $pending,
            ]);
        }

        return ['open_assigned' => $open, 'pending_customer_assigned' => $pending];
    }

    /** FS-01: every agent named by a ticket or a stats row, recomputed. @return int agents recomputed */
    public function rebuild(): int
    {
        /** @var list<string> $ids */
        $ids = DB::table('tickets')->whereNotNull('assignee_id')->distinct()->pluck('assignee_id')->all();
        /** @var list<string> $stale */
        $stale = DB::table('agent_assignment_stats')->pluck('user_id')->all();
        $all = array_values(array_unique([...$ids, ...$stale]));
        foreach ($all as $userId) {
            $this->recompute($userId);
        }

        return count($all);
    }
}
