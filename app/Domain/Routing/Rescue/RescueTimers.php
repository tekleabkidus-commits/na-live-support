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
}
