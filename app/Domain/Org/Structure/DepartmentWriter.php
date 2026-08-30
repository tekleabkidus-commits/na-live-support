<?php

declare(strict_types=1);

namespace App\Domain\Org\Structure;

use App\Domain\Org\OrgRefused;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-01 / FR-18 / EC-10 / BR-04 — departments as operator CONTENT.
 *
 * - create() makes the department AND its first queue (the default) in ONE transaction
 *   (FLAG A's countersigned shape — no observable defaultless department).
 * - EC-10 at the SERVICE door: the default queue must belong to the department and be
 *   active; deactivating a department's default queue is refused here and in
 *   QueueWriter (circular fallbacks cannot be configured).
 * - rename is free (FR-18: references are by id); reorder is an int position.
 * - deactivate stops intake (FR-18); DELETE is the wizard's (step 10) — never here.
 * - business_hours_override_ref is stored, never read ([D12]).
 */
final class DepartmentWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string>  $name  per-language {lang: text}
     * @return array{department_id: string, queue_id: string}
     */
    public function create(array $name, string $slug, string $actorId, ?string $description = null, ?string $hoursOverrideRef = null): array
    {
        $this->assertName($name);
        $tenantId = $this->context->tenantId();
        $departmentId = (string) Str::uuid7();
        $queueId = (string) Str::uuid7();
        $max = DB::table('departments')->whereNull('deleted_at')->max('position');
        $position = (is_numeric($max) ? (int) $max : 0) + 1;

        DB::transaction(function () use ($tenantId, $departmentId, $queueId, $name, $slug, $description, $hoursOverrideRef, $position, $actorId): void {
            DB::table('departments')->insert([
                'id' => $departmentId, 'tenant_id' => $tenantId,
                'name' => json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'slug' => $slug,
                'description' => $description === null ? null : json_encode(['en' => $description], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'position' => $position, 'default_queue_id' => $queueId, 'business_hours_override_ref' => $hoursOverrideRef,
            ]);
            DB::table('queues')->insert([
                'id' => $queueId, 'tenant_id' => $tenantId, 'department_id' => $departmentId, 'name' => $name['en'] ?? reset($name), 'position' => 0,
            ]);
            $this->events->publish('org.department.created', ['tenant_id' => $tenantId, 'department_id' => $departmentId]);
            $this->audit->record('org.department.created', 'department', $departmentId, AuditActor::Staff, ['department_id' => $departmentId, 'name' => $name], actorId: $actorId);
            $this->events->publish('org.queue.created', ['tenant_id' => $tenantId, 'queue_id' => $queueId]);
            $this->audit->record('org.queue.created', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'department_id' => $departmentId, 'assignment_strategy' => 'round_robin'], actorId: $actorId);
        });

        return ['department_id' => $departmentId, 'queue_id' => $queueId];
    }

    /** @param  array<string, mixed>  $changes  name|slug|description|default_queue_id|business_hours_override_ref|is_active */
    public function update(string $departmentId, array $changes, string $actorId): void
    {
        $department = $this->live($departmentId);
        $update = [];
        if (array_key_exists('name', $changes)) {
            $this->assertName($changes['name']);
            $update['name'] = json_encode($changes['name'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        foreach (['slug', 'business_hours_override_ref'] as $k) {
            if (array_key_exists($k, $changes)) {
                $update[$k] = $changes[$k];
            }
        }
        if (array_key_exists('description', $changes)) {
            $update['description'] = $changes['description'] === null ? null : json_encode(['en' => $changes['description']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('default_queue_id', $changes)) {
            $queueId = $changes['default_queue_id'];
            if (! is_string($queueId)) {
                throw new OrgRefused('department_default_queue_invalid', 'default_queue_id is a queue id (EC-10).');
            }
            $this->assertDefaultQueue($departmentId, $queueId);
            $update['default_queue_id'] = $queueId;
        }
        if (array_key_exists('is_active', $changes)) {
            $update['is_active'] = (bool) $changes['is_active'];
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');

        DB::transaction(function () use ($departmentId, $update, $changes, $department, $actorId): void {
            DB::table('departments')->where('id', $departmentId)->update($update);
            $this->events->publish('org.department.updated', ['tenant_id' => $this->context->tenantId(), 'department_id' => $departmentId, 'changed' => array_keys($changes)]);
            $this->audit->record('org.department.updated', 'department', $departmentId, AuditActor::Staff, ['department_id' => $departmentId, 'changed' => array_keys($changes)], actorId: $actorId);
            unset($department);
        });
    }

    /** FR-18 / §8: reorder by position (move buttons — §13). */
    public function reorder(string $departmentId, int $newPosition, string $actorId): void
    {
        $department = $this->live($departmentId);
        $old = (int) $department->position;
        DB::transaction(function () use ($departmentId, $old, $newPosition, $actorId): void {
            DB::table('departments')->where('id', $departmentId)->update(['position' => $newPosition, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('org.department.reordered', 'department', $departmentId, AuditActor::Staff, ['department_id' => $departmentId, 'old_position' => $old, 'new_position' => $newPosition], actorId: $actorId);
        });
    }

    /** EC-10: a department's default queue must be one of ITS live, active queues. */
    public function assertDefaultQueue(string $departmentId, string $queueId): void
    {
        $ok = DB::table('queues')->where('id', $queueId)->where('department_id', $departmentId)->whereNull('deleted_at')->where('is_active', true)->exists();
        if (! $ok) {
            throw new OrgRefused('department_default_queue_invalid', 'the default queue must be an active queue of this department (EC-10 — no defaultless or circular fallback).');
        }
    }

    /** @return object{id: string, position: int|string, default_queue_id: ?string} */
    private function live(string $departmentId): object
    {
        /** @var object{id: string, position: int|string, default_queue_id: ?string}|null $row */
        $row = DB::table('departments')->where('id', $departmentId)->whereNull('deleted_at')->first(['id', 'position', 'default_queue_id']);
        if ($row === null) {
            throw new OrgRefused('department_not_found', 'unknown department');
        }

        return $row;
    }

    private function assertName(mixed $name): void
    {
        if (! is_array($name) || $name === [] || ! isset($name['en']) || ! is_string($name['en']) || trim($name['en']) === '') {
            throw new OrgRefused('department_name_required', 'a department name needs at least its English text (FR-01: i18n-capable operator content).');
        }
    }
}
