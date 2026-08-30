<?php

declare(strict_types=1);

namespace App\Domain\Routing\Temporary;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityCacheInvalidator;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 T-07 / EC-07 / FS-05 — the 1-minute temporary-membership expiry sweep, the
 * CLEANUP half only. Security never waits for it: every membership read (OrgMembership,
 * EffectiveMembers, RoutableAgents) refuses an expired row by DB time already (T-07's
 * "resolver time check"; AC-05's independence bar — disable this sweep and no
 * assignment is ever made on an expired grant, FS-05). What this pass does is END the
 * rows (`ended_at`, never deleted — FLAG C), publish `queue.temporary_grant.expired`
 * (Doc 07 EC-09's queue-view vanish; Doc 17) and write `routing.pull_in.ended`
 * (system, cause=expired) — the trail.
 *
 * EC-07: assignments already made PERSIST — this class never touches `tickets` (the
 * static RoutingNeverWritesTicketsTest sweep covers this directory); the agent keeps
 * the tickets they hold and simply receives no new ones.
 *
 * The Doc 03 override itself is tidied by `rbac:expire-overrides` (its FR-09 cleanup
 * half); that command ALSO ends these rows as a backstop (the step-3 core edit), so the
 * two sweeps are order-independent: whichever runs first ends the rows; the other finds
 * nothing live and drops its member. In-transaction re-check under the row lock (the
 * Doc 06 EC-01 precedent) — a member that is not due yet by DB time is DEFERRED, one
 * whose rows are already ended is DROPPED.
 */
final class ExpirySweep
{
    use RunsWithinTenant;

    public function __construct(
        private readonly ExpiryZset $zset,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly CapabilityCacheInvalidator $invalidator,
    ) {}

    /** @return array{ended: int, dropped: int, deferred: int} */
    public function sweep(int $limit = 500): array
    {
        $ended = 0;
        $dropped = 0;
        $deferred = 0;
        foreach ($this->zset->due($limit) as $member) {
            [$tenantId, $overrideId] = $this->zset->parseMember($member);
            $outcome = $this->withinTenant($tenantId, fn (): string => $this->sweepOne($tenantId, $overrideId));
            match ($outcome) {
                'ended' => $ended++,
                'dropped' => $dropped++,
                default => $deferred++,
            };
        }

        return ['ended' => $ended, 'dropped' => $dropped, 'deferred' => $deferred];
    }

    /** One override's rows, ended jointly (A-50 obligation 4). */
    public function sweepOne(string $tenantId, string $overrideId): string
    {
        $outcome = DB::transaction(function () use ($tenantId, $overrideId): string {
            /** @var list<object{id: string, queue_id: string, user_id: string, is_due: bool}> $queueRows */
            $queueRows = DB::select(
                'SELECT id, queue_id, user_id, (expires_at <= clock_timestamp()) AS is_due
                   FROM queue_members WHERE override_id = ? AND kind = \'temporary\' AND ended_at IS NULL FOR UPDATE',
                [$overrideId],
            );
            /** @var list<object{id: string, is_due: bool}> $deptRows */
            $deptRows = DB::select(
                'SELECT id, (expires_at <= clock_timestamp()) AS is_due
                   FROM user_departments WHERE override_id = ? AND kind = \'temporary\' AND ended_at IS NULL FOR UPDATE',
                [$overrideId],
            );
            if ($queueRows === [] && $deptRows === []) {
                return 'dropped';
            }
            foreach ([...$queueRows, ...$deptRows] as $row) {
                if (! $row->is_due) {
                    return 'deferred';   // the DB clock disagrees with the short-list: not yet
                }
            }

            DB::table('queue_members')->where('override_id', $overrideId)->whereNull('ended_at')
                ->update(['ended_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            DB::table('user_departments')->where('override_id', $overrideId)->whereNull('ended_at')
                ->update(['ended_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);

            foreach ($queueRows as $row) {
                $this->events->publish('queue.temporary_grant.expired', [
                    'tenant_id' => $tenantId, 'queue_id' => $row->queue_id, 'user_id' => $row->user_id, 'override_id' => $overrideId, 'cause' => 'expired',
                ]);
                $this->audit->record('routing.pull_in.ended', 'queue', $row->queue_id, AuditActor::System, [
                    'queue_id' => $row->queue_id, 'user_id' => $row->user_id, 'override_id' => $overrideId, 'cause' => 'expired',
                ]);
                $this->invalidator->forgetUserId($row->user_id);
            }

            return 'ended';
        });

        if ($outcome !== 'deferred') {
            $this->zset->remove($tenantId, $overrideId);
        }

        return $outcome;
    }
}
