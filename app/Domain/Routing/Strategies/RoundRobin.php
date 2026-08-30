<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Doc 11 FR-09 / T-04 / EC-02 — ROUND-ROBIN: a rotating pointer per queue over the ELIGIBLE
 * agents (at-capacity and away agents are simply not in the routable set — "skips"), the
 * pointer persisted in Redis (durable) with a DB checkpoint (queues.rr_pointer_user_id),
 * and joins/leaves SPLICE without resetting fairness: the pointer is a USER ID, not an index,
 * so the next turn is "the first routable agent AFTER the pointer in user-id order, wrapping"
 * — a member joining or leaving changes the ring, never the pointer's place in it (EC-02: an
 * agent going Away keeps their turn for when they return).
 *
 * The candidate ORDER is the whole ring starting after the pointer (T-03: race → next). The
 * dispatcher advances the pointer to the agent it actually ASSIGNED (advance()).
 */
final class RoundRobin implements AssignmentStrategy
{
    private const CONNECTION = 'durable';

    public function __construct(private readonly TenantContext $context) {}

    public function name(): string
    {
        return 'round_robin';
    }

    public function order(string $queueId, array $routable, array $ticket): array
    {
        $ids = array_column($routable, 'user_id');   // already sorted by user id (the ring)
        if ($ids === []) {
            return ['candidates' => [], 'note' => 'no routable agent'];
        }
        $pointer = $this->pointer($queueId);
        $start = 0;
        if ($pointer !== null) {
            foreach ($ids as $i => $id) {
                if (strcmp($id, $pointer) > 0) {
                    $start = $i;
                    break;
                }
                $start = 0; // pointer is past the last id → wrap
            }
            if (strcmp($ids[count($ids) - 1], $pointer) <= 0) {
                $start = 0;
            }
        }
        $ring = [...array_slice($ids, $start), ...array_slice($ids, 0, $start)];

        return ['candidates' => $ring, 'note' => sprintf('pointer %s → next %s (%d in the ring)', $pointer ?? 'none', $ring[0], count($ring))];
    }

    /** The persisted pointer: Redis first, the DB checkpoint when Redis has nothing (FS-01 — a fairness restart is noted by the caller). */
    public function pointer(string $queueId): ?string
    {
        $cached = Redis::connection(self::CONNECTION)->get($this->key($queueId));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $checkpoint = DB::table('queues')->where('id', $queueId)->value('rr_pointer_user_id');

        return is_string($checkpoint) ? $checkpoint : null;
    }

    /** After a successful assignment: the pointer moves to the assigned agent (Redis + DB checkpoint). */
    public function advance(string $queueId, string $userId): void
    {
        Redis::connection(self::CONNECTION)->set($this->key($queueId), $userId);
        DB::table('queues')->where('id', $queueId)->update(['rr_pointer_user_id' => $userId, 'updated_at' => DB::raw('clock_timestamp()')]);
    }

    private function key(string $queueId): string
    {
        return sprintf('t:%s:routing:rr:%s', $this->context->tenantId(), $queueId);
    }
}
