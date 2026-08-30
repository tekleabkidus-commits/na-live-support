<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Doc 11 FR-16a / EC-08 (A-54) — ONE deadline ZSET for routing's two timers, the Doc 06
 * `TicketTimers` shape (redis-durable, member "{tenant}|{kind}|{id}", score = deadline epoch
 * seconds, short-listed by DB time, decided by the sweep's in-transaction re-check):
 *   kind `heartbeat` — id = the agent's user id (routing.rescue.minutes after heartbeat loss);
 *   kind `hold`      — id = the courtesy-hold decision id (routing.reopen.wait_for_previous).
 * Swept by `routing:rescue-sweep` (P-C3's ONE allowlist entry; a deadline sweep, not a queue
 * poll). `rebuild()` REPLACES the tenant's members from truth (FS-01).
 */
final class RescueTimers
{
    public const ZSET = 'routing_rescue';

    private const CONNECTION = 'durable';

    public function member(string $tenantId, string $kind, string $id): string
    {
        return $tenantId.'|'.$kind.'|'.$id;
    }

    /** @return array{0: string, 1: string, 2: string} [tenant, kind, id] */
    public function parseMember(string $member): array
    {
        [$tenantId, $kind, $id] = explode('|', $member, 3);

        return [$tenantId, $kind, $id];
    }

    public function arm(string $tenantId, string $kind, string $id, int $deadlineEpochSeconds): void
    {
        Redis::connection(self::CONNECTION)->zadd(self::ZSET, [$this->member($tenantId, $kind, $id) => $deadlineEpochSeconds]);
    }

    public function disarm(string $tenantId, string $kind, string $id): void
    {
        Redis::connection(self::CONNECTION)->zrem(self::ZSET, $this->member($tenantId, $kind, $id));
    }

    public function score(string $tenantId, string $kind, string $id): ?int
    {
        $score = Redis::connection(self::CONNECTION)->zscore(self::ZSET, $this->member($tenantId, $kind, $id));

        return is_numeric($score) ? (int) $score : null;
    }

    /** @return list<string> */
    public function due(int $limit = 500): array
    {
        /** @var object{s: string} $row */
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM clock_timestamp())::bigint AS s');
        /** @var list<string> $members */
        $members = Redis::connection(self::CONNECTION)->zrangebyscore(self::ZSET, '-inf', $row->s, ['limit' => ['offset' => 0, 'count' => $limit]]);

        return $members;
    }

    /** DB time as epoch seconds (C-11). */
    public function now(): int
    {
        /** @var object{s: string} $row */
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM clock_timestamp())::bigint AS s');

        return (int) $row->s;
    }

    /**
     * FS-01 (the step-9 carry, landed step 10): REPLACE this tenant's members from truth — heartbeat
     * deadlines from `agent_statuses` rows that are away/heartbeat_lost with open assigned tickets
     * (deadline = since + routing.rescue.minutes), holds from open `reopened/waiting` decisions whose
     * `hold_until` is ahead and whose ticket still sits with `hold_for`.
     */
    public function rebuild(string $tenantId, int $rescueMinutes): int
    {
        $redis = Redis::connection(self::CONNECTION);
        /** @var list<string> $members */
        $members = $redis->zrange(self::ZSET, 0, -1);
        foreach ($members as $member) {
            if (str_starts_with($member, $tenantId.'|')) {
                $redis->zrem(self::ZSET, $member);
            }
        }
        $n = 0;
        /** @var list<object{user_id: string, s: string}> $lost */
        $lost = DB::select(
            "SELECT a.user_id, (EXTRACT(EPOCH FROM a.since) + ? * 60)::bigint AS s
               FROM agent_statuses a
              WHERE a.status = 'away' AND a.reason = 'heartbeat_lost'
                AND EXISTS (SELECT 1 FROM tickets t WHERE t.assignee_id = a.user_id AND t.status IN ('new','open','pending_customer','pending_internal','escalated'))",
            [$rescueMinutes],
        );
        foreach ($lost as $row) {
            $this->arm($tenantId, 'heartbeat', $row->user_id, (int) $row->s);
            $n++;
        }
        /** @var list<object{id: string, s: string}> $holds */
        $holds = DB::select(
            "SELECT d.id, (d.snapshot->>'hold_until')::bigint AS s
               FROM routing_decisions d JOIN tickets t ON t.id = d.ticket_id
              WHERE d.trigger = 'reopened' AND d.outcome = 'waiting'
                AND (d.snapshot->>'hold_until')::bigint > EXTRACT(EPOCH FROM clock_timestamp())
                AND t.assignee_id::text = d.snapshot->>'hold_for'",
        );
        foreach ($holds as $row) {
            $this->arm($tenantId, 'hold', $row->id, (int) $row->s);
            $n++;
        }

        return $n;
    }
}
