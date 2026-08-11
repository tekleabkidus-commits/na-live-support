<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Status;

use App\Models\User;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-28 (S07 step 12): Online / Away / Offline — the ROUTING truth, not
 * surveillance (BR-06: active-time metrics derive from activity events,
 * never from this state).
 *
 * HEARTBEAT PHYSICS: presence lives where presence belongs — a TTL'd key in
 * redis-volatile (C-07), refreshed by the client's heartbeat. "Heartbeat
 * loss > 90 s → system-away" is MATERIALIZED ON READ: any read that finds
 * an `online` row with a dead heartbeat key writes the away/heartbeat_lost
 * row and publishes the transition — no scheduler needed for correctness,
 * and Doc 11's rescue timers get real rows either way.
 *
 * Every transition publishes `agent.status_changed` (catalogued, v1)
 * through the OUTBOX (C-06); Doc 11 consumes routability, Doc 15 counts
 * transitions. A FORCED status stores the supervisor's id (attribution —
 * "set by Dawit") and the DOOR audits it.
 */
final class AgentStatus
{
    private const HEARTBEAT_TTL_SECONDS = 90;

    private const STATUSES = ['online', 'away', 'offline'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RedisFactory $redis,
        private readonly EventPublisher $events,
    ) {}

    private function heartbeatKey(string $userId): string
    {
        return sprintf('t:%s:agent_heartbeat:%s', $this->context->tenantId(), $userId);
    }

    public function heartbeat(User $user): void
    {
        $this->redis->connection('volatile')->command('setex', [
            $this->heartbeatKey($user->identifier()), self::HEARTBEAT_TTL_SECONDS, '1',
        ]);
    }

    /** The user's own transition (manual|auto_idle|logout reasons). */
    public function set(User $user, string $status, string $reason = 'manual'): void
    {
        $this->write($user->identifier(), $status, $reason, null);
        if ($status === 'online') {
            $this->heartbeat($user); // going online IS a heartbeat
        }
    }

    /** The supervisor door — attribution stored; the ROUTE audits. */
    public function force(User $supervisor, string $targetUserId, string $status): void
    {
        $this->write($targetUserId, $status, 'forced', $supervisor->identifier());
    }

    /**
     * Effective status, with heartbeat-loss MATERIALIZED: an online row
     * whose heartbeat key expired becomes away/heartbeat_lost — written and
     * published, not just displayed.
     *
     * @return array{status: string, reason: string|null, since: string|null, set_by_user_id: string|null}
     */
    public function current(string $userId): array
    {
        /** @var object{status: string, reason: string|null, since: string|null, set_by_user_id: string|null}|null $row */
        $row = DB::table('agent_statuses')->where('user_id', $userId)->first(['status', 'reason', 'since', 'set_by_user_id']);

        if ($row === null) {
            return ['status' => 'offline', 'reason' => null, 'since' => null, 'set_by_user_id' => null];
        }

        if ($row->status === 'online') {
            /** @var int|string $alive */
            $alive = $this->redis->connection('volatile')->command('exists', [$this->heartbeatKey($userId)]);
            if ((int) $alive === 0) {
                $this->write($userId, 'away', 'heartbeat_lost', null);

                return $this->current($userId);
            }
        }

        return ['status' => $row->status, 'reason' => $row->reason, 'since' => $row->since, 'set_by_user_id' => $row->set_by_user_id];
    }

    private function write(string $userId, string $status, string $reason, ?string $setByUserId): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new DomainException('unknown agent status');
        }

        DB::transaction(function () use ($userId, $status, $reason, $setByUserId): void {
            DB::table('agent_statuses')->upsert(
                [[
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $this->context->tenantId(),
                    'user_id' => $userId,
                    'status' => $status,
                    'reason' => $reason,
                    'set_by_user_id' => $setByUserId,
                    'since' => DB::raw('clock_timestamp()'),
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]],
                ['tenant_id', 'user_id'],
                ['status', 'reason', 'set_by_user_id', 'since', 'updated_at'],
            );

            // C-06: the transition rides the outbox inside this transaction.
            $this->events->publish('agent.status_changed', [
                'tenant_id' => $this->context->tenantId(),
                'user_id' => $userId,
                'status' => $status,
                'reason' => $reason,
                'set_by_user_id' => $setByUserId,
            ]);
        });
    }
}
