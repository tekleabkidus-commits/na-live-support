<?php

declare(strict_types=1);

namespace App\Domain\Routing\Temporary;

use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Rbac\OverrideAdministration;
use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Routing\RoutingRefused;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-15 (pull-in) / FR-03's temporary half / T-07 / BR-07 / SEC-01 / SEC-03 /
 * EC-07 / AC-05; Doc 03 FR-09's second half; A-50 obligation 4; A-53; A-56.
 *
 * "Add an agent to a queue temporarily (expiry default shift-end via business hours,
 * editable) creating the Doc 03 temporary grant." THE ROUTER HOLDS NO GRANT AUTHORITY
 * (SEC-03): the grant is `OverrideAdministration::add()` — Doc 03's audited machinery,
 * its SEC-04 reason rule, its FR-08b four-eyes — carrying `tickets.assign_self` (A-56:
 * the one capability a pulled-in agent needs for the shift; NORMAL risk, so four-eyes can
 * never strand a pull-in — the door refuses `pull_in_pending` if it ever did). The
 * override row is the single source the routing rows hang on (SCHEMA AMENDMENT 5's
 * `temporary ⇔ override_id` CHECK): the `queue_members` temporary row and — when the
 * agent lacks live access to the queue's department — a `user_departments` temporary
 * row, all three on ONE `override_id`, all three expiring JOINTLY (A-50 obligation 4),
 * created in ONE transaction so a partial pull-in cannot exist.
 *
 * SEC-01: the granter needs `org.queues.pull_in` and the queue must lie within the
 * granter's own ticket scope (EffectiveMembers::reaches — the same reach FR-04 uses).
 *
 * ONE LIVE PULL-IN PER AGENT: Doc 03's index physics allow one live override per
 * user+permission, and `OverrideAdministration::add()` REPLACES a live one. A second
 * concurrent pull-in of the same agent would therefore silently orphan the first
 * queue's rows from their grant — so the door refuses `pull_in_active` naming the live
 * queue; end that one first (US-03 moves an agent INTO one queue for the evening).
 *
 * BR-07: "temporary means temporary" — expiry is mechanical (T-07's two paths); the
 * recurrence HINT (the same agent pulled into the same queue ≥ routing.pull_in.recurrence_hint
 * times within seven days) is returned as an i18n KEY for the UI to nudge — nothing is
 * converted, no row changes shape.
 */
final class PullIn
{
    public const GRANTED_PERMISSION = 'tickets.assign_self';

    public function __construct(
        private readonly TenantContext $context,
        private readonly OverrideAdministration $overrides,
        private readonly EffectiveMembers $members,
        private readonly CapabilityMapProvider $maps,
        private readonly HoursContract $hours,
        private readonly SettingsRepository $settings,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly ExpiryZset $zset,
    ) {}

    /** A-53: the dialog's defaulted expiry for a queue (tenant-local shift end, returned in UTC). */
    public function defaultExpiry(string $queueId): DateTimeImmutable
    {
        return $this->hours->shiftEnd($this->liveQueue($queueId)->department_id);
    }

    /**
     * @return array{override_id: string, queue_member_id: string, department_access_id: ?string, expires_at: string, hint: ?string}
     */
    public function create(User $granter, string $queueId, string $userId, ?DateTimeInterface $expiresAt, string $reason): array
    {
        $queue = $this->liveQueue($queueId);
        $this->guardGranter($granter, $queue->department_id);

        $subject = User::query()->find($userId);
        if (! $subject instanceof User) {
            throw new RoutingRefused('agent_not_found', 'the agent does not exist in this tenant');
        }

        $expires = $expiresAt === null
            ? $this->hours->shiftEnd($queue->department_id)
            : DateTimeImmutable::createFromInterface($expiresAt)->setTimezone(new DateTimeZone('UTC'));
        if ($expires <= $this->hours->now()) {
            throw new RoutingRefused('expiry_in_past', 'a pull-in must expire in the future (DB time)');
        }

        /** @var list<object{queue_id: string, kind: string}> $live */
        $live = DB::table('queue_members as qm')->join('queues as q', 'q.id', '=', 'qm.queue_id')
            ->where('qm.user_id', $userId)->whereNull('qm.ended_at')->whereNull('q.deleted_at')
            ->where(static function (\Illuminate\Database\Query\Builder $q): void {
                $q->where('qm.kind', 'direct')->orWhereRaw('qm.expires_at > clock_timestamp()');
            })
            ->get(['qm.queue_id', 'qm.kind'])->all();
        foreach ($live as $row) {
            if ($row->queue_id === $queueId) {
                throw new RoutingRefused('already_member', sprintf('already a %s member of this queue', $row->kind));
            }
            if ($row->kind === 'temporary') {
                throw new RoutingRefused('pull_in_active', sprintf('a live pull-in into queue %s exists — end it first', $row->queue_id));
            }
        }

        $result = DB::transaction(function () use ($granter, $subject, $queue, $queueId, $userId, $expires, $reason): array {
            // SEC-03: THE grant, through Doc 03's door — audited rbac.override.added there.
            $added = $this->overrides->add($granter, $subject, self::GRANTED_PERMISSION, 'allow', $reason, $expires);
            if ($added['pending'] || ! $added['override'] instanceof UserPermissionOverride) {
                throw new RoutingRefused('pull_in_pending', 'the grant went pending — a pull-in cannot wait for four-eyes (A-56)');
            }
            $overrideId = $added['override']->identifier();

            $memberId = (string) Str::uuid7();
            DB::table('queue_members')->insert([
                'id' => $memberId, 'tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId,
                'kind' => 'temporary', 'granted_by' => $granter->identifier(), 'override_id' => $overrideId, 'expires_at' => $expires->format('Y-m-d H:i:s.uP'),
            ]);

            $accessId = null;
            $hasAccess = DB::table('user_departments')->where('user_id', $userId)->where('department_id', $queue->department_id)->whereNull('ended_at')
                ->where(static function (\Illuminate\Database\Query\Builder $q): void {
                    $q->where('kind', 'direct')->orWhereRaw('expires_at > clock_timestamp()');
                })->exists();
            if (! $hasAccess) {
                $accessId = (string) Str::uuid7();
                DB::table('user_departments')->insert([
                    'id' => $accessId, 'tenant_id' => $this->context->tenantId(), 'user_id' => $userId, 'department_id' => $queue->department_id,
                    'kind' => 'temporary', 'granted_by' => $granter->identifier(), 'override_id' => $overrideId, 'expires_at' => $expires->format('Y-m-d H:i:s.uP'),
                ]);
            }

            $this->audit->record('routing.pull_in.created', 'queue', $queueId, AuditActor::Staff, [
                'queue_id' => $queueId, 'user_id' => $userId, 'override_id' => $overrideId, 'expires_at' => $expires->format(DateTimeInterface::ATOM),
            ], actorId: $granter->identifier());
            $this->events->publish('queue.temporary_grant.created', [
                'tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'override_id' => $overrideId, 'expires_at' => $expires->format(DateTimeInterface::ATOM),
            ]);

            return ['override_id' => $overrideId, 'queue_member_id' => $memberId, 'department_access_id' => $accessId, 'expires_at' => $expires->format(DateTimeInterface::ATOM)];
        });

        // T-07: the timer member, AFTER the commit (a lost add is cleanup latency the
        // rbac tidy and routing:rebuild cover; never a security gap).
        $this->zset->add($this->context->tenantId(), $result['override_id'], (int) $expires->format('U'));

        return $result + ['hint' => $this->recurrenceHint($queueId, $userId)];
    }

    /** Manual end before expiry — the Doc 03 override removed through its door, the rows ended jointly. */
    public function end(User $actor, string $queueId, string $userId): void
    {
        $queue = $this->liveQueue($queueId);
        $this->guardGranter($actor, $queue->department_id);

        /** @var object{override_id: string}|null $row */
        $row = DB::table('queue_members')->where('queue_id', $queueId)->where('user_id', $userId)->where('kind', 'temporary')->whereNull('ended_at')->first(['override_id']);
        if ($row === null) {
            throw new RoutingRefused('pull_in_not_found', 'no live pull-in for this agent in this queue');
        }
        $override = UserPermissionOverride::query()->whereKey($row->override_id)->first();

        DB::transaction(function () use ($actor, $queueId, $userId, $row, $override): void {
            if ($override instanceof UserPermissionOverride && $override->revoked_at === null) {
                $this->overrides->remove($actor, $override);   // Doc 03's door: rbac.override.removed audited there
            }
            DB::table('queue_members')->where('override_id', $row->override_id)->whereNull('ended_at')
                ->update(['ended_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            DB::table('user_departments')->where('override_id', $row->override_id)->whereNull('ended_at')
                ->update(['ended_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);

            $this->audit->record('routing.pull_in.ended', 'queue', $queueId, AuditActor::Staff, [
                'queue_id' => $queueId, 'user_id' => $userId, 'override_id' => $row->override_id, 'cause' => 'ended_by_staff',
            ], actorId: $actor->identifier());
            $this->events->publish('queue.temporary_grant.expired', [
                'tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'override_id' => $row->override_id, 'cause' => 'ended_by_staff',
            ]);
        });

        $this->zset->remove($this->context->tenantId(), $row->override_id);
    }

    /**
     * BR-07: the nudge KEY when the same pull-in recurs ≥ routing.pull_in.recurrence_hint
     * times in seven days (this one counted) — a hint, never a conversion.
     */
    public function recurrenceHint(string $queueId, string $userId): ?string
    {
        $threshold = $this->settings->get('routing.pull_in.recurrence_hint');
        $threshold = is_numeric($threshold) ? (int) $threshold : 3;
        $count = DB::table('queue_members')->where('queue_id', $queueId)->where('user_id', $userId)->where('kind', 'temporary')
            ->whereRaw("created_at > clock_timestamp() - interval '7 days'")->count();

        return $count >= $threshold ? 'routing.notify.pull_in_recurring' : null;
    }

    /** @return object{id: string, department_id: string, is_active: bool} */
    private function liveQueue(string $queueId): object
    {
        /** @var object{id: string, department_id: string, is_active: bool}|null $queue */
        $queue = DB::table('queues')->where('id', $queueId)->whereNull('deleted_at')->first(['id', 'department_id', 'is_active']);
        if ($queue === null) {
            throw new RoutingRefused('queue_not_found', 'no live queue');
        }
        if (! $queue->is_active) {
            throw new RoutingRefused('queue_inactive', 'a deactivated queue takes no intake and no pull-ins');
        }

        return $queue;
    }

    /** SEC-01: `org.queues.pull_in` AND the queue within the granter's own scope. */
    private function guardGranter(User $granter, string $departmentId): void
    {
        if (! $this->maps->verdictFor($granter, 'org.queues.pull_in')->allowed) {
            throw new RoutingRefused('forbidden', 'org.queues.pull_in required');
        }
        if (! $this->members->reaches($granter, $departmentId)) {
            throw new RoutingRefused('queue_out_of_scope', 'the queue lies outside the granter\'s scope (SEC-01)');
        }
    }
}
