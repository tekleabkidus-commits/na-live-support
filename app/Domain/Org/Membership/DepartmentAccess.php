<?php

declare(strict_types=1);

namespace App\Domain\Org\Membership;

use App\Domain\Org\OrgRefused;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-04 / A-50 point 5 (countersigned obligation 3) — J04's "Give access": ONE
 * transaction writes BOTH rows — the user_departments direct row (department access) and a
 * direct queue_members row on the department's default queue (routable there) — under ONE
 * audit action, org.department.access_granted. A partial write is impossible: the
 * transaction is the act. revoke() ENDS both direct rows (FLAG C: ended_at, never deleted)
 * under org.department.access_revoked.
 */
final class DepartmentAccess
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{department_id: string, queue_id: string} */
    public function grant(string $userId, string $departmentId, string $actorId): array
    {
        /** @var object{id: string, default_queue_id: ?string}|null $department */
        $department = DB::table('departments')->where('id', $departmentId)->whereNull('deleted_at')->first(['id', 'default_queue_id']);
        if ($department === null) {
            throw new OrgRefused('department_not_found', 'unknown department');
        }
        if ($department->default_queue_id === null) {
            throw new OrgRefused('department_default_queue_invalid', 'the department has no default queue to route through (EC-10).');
        }
        $queueId = $department->default_queue_id;
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($tenantId, $userId, $departmentId, $queueId, $actorId): void {
            if (! DB::table('user_departments')->where('user_id', $userId)->where('department_id', $departmentId)->whereNull('ended_at')->exists()) {
                DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId, 'department_id' => $departmentId, 'kind' => 'direct', 'granted_by' => $actorId]);
            }
            if (! DB::table('queue_members')->where('queue_id', $queueId)->where('user_id', $userId)->whereNull('ended_at')->exists()) {
                DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queueId, 'user_id' => $userId, 'kind' => 'direct', 'granted_by' => $actorId]);
            }
            // ONE audit action for the ONE act (A-50 obligation 3).
            $this->audit->record('org.department.access_granted', 'user', $userId, AuditActor::Staff, ['user_id' => $userId, 'department_id' => $departmentId, 'queue_id' => $queueId], actorId: $actorId);
            $this->events->publish('queue.membership_changed', ['tenant_id' => $tenantId, 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'added', 'via' => 'direct']);
        });

        return ['department_id' => $departmentId, 'queue_id' => $queueId];
    }

    public function revoke(string $userId, string $departmentId, string $actorId): void
    {
        $tenantId = $this->context->tenantId();
        DB::transaction(function () use ($tenantId, $userId, $departmentId, $actorId): void {
            $now = DB::raw('clock_timestamp()');
            DB::table('user_departments')->where('user_id', $userId)->where('department_id', $departmentId)->where('kind', 'direct')->whereNull('ended_at')->update(['ended_at' => $now, 'updated_at' => $now]);
            /** @var list<string> $queueIds */
            $queueIds = DB::table('queues')->where('department_id', $departmentId)->whereNull('deleted_at')->pluck('id')->all();
            DB::table('queue_members')->whereIn('queue_id', $queueIds)->where('user_id', $userId)->where('kind', 'direct')->whereNull('ended_at')->update(['ended_at' => $now, 'updated_at' => $now]);
            $this->audit->record('org.department.access_revoked', 'user', $userId, AuditActor::Staff, ['user_id' => $userId, 'department_id' => $departmentId], actorId: $actorId);
            foreach ($queueIds as $queueId) {
                $this->events->publish('queue.membership_changed', ['tenant_id' => $tenantId, 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'removed', 'via' => 'direct']);
            }
        });
    }
}
