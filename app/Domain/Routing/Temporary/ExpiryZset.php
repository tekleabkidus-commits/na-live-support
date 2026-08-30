<?php

declare(strict_types=1);

namespace App\Domain\Routing\Temporary;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Doc 11 T-07 — the temporary-membership EXPIRY ZSET: "ZSET by expires_at (the platform's
 * standard timer pattern), 1-min sweep". The Doc 06 `TicketTimers` shape exactly: ONE
 * platform-wide set on redis-durable, member = "{tenant_id}|{override_id}", score =
 * expires_at epoch seconds; the sweep re-checks everything under DB time inside a
 * transaction, so a wrong or missing member can only DELAY the tidy, never widen
 * access (FS-05: the resolver's own `expires_at > clock_timestamp()` check is the
 * security; this set is cleanup latency). `rebuild()` REPLACES the set from the tables
 * (FS-01's pattern, `routing:rebuild`).
 *
 * The member is the OVERRIDE id, not a queue row: the three pull-in rows (override,
 * queue_members, user_departments) share one `override_id` and expire JOINTLY (A-50
 * obligation 4) — one member, one sweep visit, every row ended together.
 */
final class ExpiryZset
{
    public const ZSET = 'routing_expiry';

    private const CONNECTION = 'durable';

    public function __construct(private readonly TenantContext $context) {}

    public function member(string $tenantId, string $overrideId): string
    {
        return $tenantId.'|'.$overrideId;
    }

    /** @return array{0: string, 1: string} [tenant_id, override_id] */
    public function parseMember(string $member): array
    {
        [$tenantId, $overrideId] = explode('|', $member, 2);

        return [$tenantId, $overrideId];
    }

    public function add(string $tenantId, string $overrideId, int $expiresAtEpochSeconds): void
    {
        Redis::connection(self::CONNECTION)->zadd(self::ZSET, [$this->member($tenantId, $overrideId) => $expiresAtEpochSeconds]);
    }

    public function remove(string $tenantId, string $overrideId): void
    {
        Redis::connection(self::CONNECTION)->zrem(self::ZSET, $this->member($tenantId, $overrideId));
    }

    public function has(string $tenantId, string $overrideId): bool
    {
        // phpredis returns FALSE for a missing member (predis null) — ask for both.
        $score = Redis::connection(self::CONNECTION)->zscore(self::ZSET, $this->member($tenantId, $overrideId));

        return is_numeric($score);
    }

    /**
     * The candidates due by the DATABASE clock (C-11) — a short-list; the sweep's
     * in-transaction re-check decides.
     *
     * @return list<string>
     */
    public function due(int $limit = 500): array
    {
        /** @var object{s: string} $row */
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM clock_timestamp())::bigint AS s');
        /** @var list<string> $members */
        $members = Redis::connection(self::CONNECTION)->zrangebyscore(self::ZSET, '-inf', $row->s, ['limit' => ['offset' => 0, 'count' => $limit]]);

        return $members;
    }

    /**
     * FS-01: REPLACE this tenant's members from its live temporary rows (both tables, one
     * member per override). Tenant-scoped like the rest of `routing:rebuild` (bound, RLS);
     * other tenants' members are untouched.
     */
    public function rebuild(): int
    {
        $tenantId = $this->context->tenantId();
        $redis = Redis::connection(self::CONNECTION);
        /** @var list<string> $members */
        $members = $redis->zrange(self::ZSET, 0, -1);
        foreach ($members as $member) {
            if (str_starts_with($member, $tenantId.'|')) {
                $redis->zrem(self::ZSET, $member);
            }
        }

        /** @var list<object{override_id: string, s: string}> $rows */
        $rows = DB::select(
            "SELECT override_id, MIN(EXTRACT(EPOCH FROM expires_at))::bigint AS s
               FROM (SELECT override_id, expires_at FROM queue_members WHERE kind = 'temporary' AND ended_at IS NULL
                     UNION ALL
                     SELECT override_id, expires_at FROM user_departments WHERE kind = 'temporary' AND ended_at IS NULL) live
              GROUP BY override_id",
        );
        foreach ($rows as $row) {
            $this->add($tenantId, $row->override_id, (int) $row->s);
        }

        return count($rows);
    }
}
