<?php

declare(strict_types=1);

namespace App\Domain\Org\Restructure;

use App\Domain\Org\Structure\QueueWriter;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-18 "Delete department: requires reassigning its queues/tickets and updating rules
 * (guided wizard listing every reference: rules, bot routing tags, SLA policies, views); RG
 * department special-case per FR-01" / AC-07 / SEC-04 / SS-03.
 *
 * Every ticket of the department moves to ONE target queue in another live department (Doc 06's
 * TransferTicket door per ticket: department + queue in one act, the operator's live scope
 * re-checked per chunk); every queue of the department is tombstoned (each `org.queue.deleted`,
 * the orphan net behind it); the RG special-case: when the department holds the tenant's is_rg
 * queue, a replacement RG target queue OUTSIDE the department is REQUIRED and becomes is_rg
 * after the old one is gone (FR-01 — Doc 08/09's routing contract never dangles); then the
 * department is tombstoned and `org.department.deleted` fires. Typed confirmation: the SAME
 * query (tickets of the department), recomputed at execute.
 */
final class DeleteDepartmentWizard
{
    public const WIZARD = 'delete_department';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ReferenceInventory $inventory,
        private readonly ChunkedMover $mover,
        private readonly QueueWriter $queues,
        private readonly CapabilityMapProvider $maps,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(string $departmentId, User $operator): array
    {
        $inv = $this->inventory->forDepartment($departmentId, $operator);

        return $inv + [
            'choices' => [
                'target_queue_id' => ['required' => $inv['tickets']['total'] > 0, 'description' => 'a live queue in ANOTHER department every ticket moves to'],
                'rules' => array_map(static fn (array $r): array => ['id' => $r['id'], 'new_target_queue_id' => null], $inv['rules']),
                'bots' => array_map(static fn (array $b): array => ['id' => $b['id'], 'new_target_queue_id' => null], $inv['bots']),
                'sla_policies' => array_map(static fn (array $p): array => ['id' => $p['id'], 'new_target_queue_id' => null], $inv['sla_policies']),
                'replacement_rg_queue_id' => ['required' => (bool) $inv['target']['holds_rg_queue'], 'description' => 'the RG target queue outside this department (FR-01)'],
            ],
            'confirmation' => $this->inventory->confirmationFor($this->inventory->ticketsOfDepartment($departmentId)),
        ];
    }

    /**
     * @param  array<string, mixed>  $choices
     * @param  callable(int, int): void|null  $betweenChunks
     * @return array{moved: int, chunks: int, deleted_department_id: string, deleted_queue_ids: list<string>}
     */
    public function execute(string $departmentId, array $choices, string $typedConfirmation, User $operator, ?callable $betweenChunks = null): array
    {
        if (! $this->maps->verdictFor($operator, 'org.departments.manage')->allowed) {
            throw new RestructureRefused('forbidden', 'org.departments.manage required');
        }
        $inv = $this->inventory->forDepartment($departmentId, $operator);
        $expected = $this->inventory->confirmationFor($this->inventory->ticketsOfDepartment($departmentId));
        if ($typedConfirmation !== $expected) {
            throw new RestructureRefused('confirmation_stale', sprintf('the typed confirmation does not match the live count (%s)', $expected));
        }
        $targetRaw = $choices['target_queue_id'] ?? null;
        $target = is_string($targetRaw) ? $targetRaw : null;
        if ($inv['tickets']['total'] > 0 && $target === null) {
            throw new RestructureRefused('target_queue_required', 'this department holds tickets — choose the queue they move to');
        }
        if ($target !== null) {
            $targetDept = DB::table('queues')->where('id', $target)->whereNull('deleted_at')->where('is_active', true)->value('department_id');
            if (! is_string($targetDept) || $targetDept === $departmentId) {
                throw new RestructureRefused('target_queue_invalid', 'the target queue must be live, active and in ANOTHER department');
            }
        }
        $replacementRg = $choices['replacement_rg_queue_id'] ?? null;
        if ($inv['target']['holds_rg_queue']) {
            $rgDept = is_string($replacementRg) ? DB::table('queues')->where('id', $replacementRg)->whereNull('deleted_at')->where('is_active', true)->value('department_id') : null;
            if (! is_string($rgDept) || $rgDept === $departmentId) {
                throw new RestructureRefused('replacement_rg_required', 'this department holds the RG queue — a replacement RG target queue in another department is required (FR-01)');
            }
        }
        foreach ($inv['rules'] as $rule) {
            $ok = false;
            foreach ((array) ($choices['rules'] ?? []) as $c) {
                if (is_array($c) && ($c['id'] ?? null) === $rule['id'] && is_string($c['new_target_queue_id'] ?? null)) {
                    $ok = true;
                }
            }
            if (! $ok) {
                throw new RestructureRefused('rule_choice_required', sprintf('rule "%s" targets a queue of this department — choose where it goes (R-05)', $rule['name']));
            }
        }

        $moved = ['moved' => 0, 'chunks' => 0];
        if ($target !== null) {   // a target with nothing to move is a no-op move (the counts were checked above)
            $moved = $this->mover->move(self::WIZARD, $departmentId, $this->inventory->ticketsOfDepartment($departmentId), $target, $operator, 'org.departments.manage', $betweenChunks);
        }

        $deletedQueues = [];
        DB::transaction(function () use ($departmentId, $inv, $target, $moved, $replacementRg, $choices, $operator, &$deletedQueues): void {
            foreach ($inv['queues'] as $q) {
                DB::table('queues')->where('id', $q['id'])->update(['is_active' => false, 'is_rg' => false, 'deleted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
                $this->audit->record('org.queue.deleted', 'queue', $q['id'], AuditActor::Staff, ['queue_id' => $q['id'], 'moved_to_queue_id' => $target, 'moved_count' => $moved['moved'], 'via' => self::WIZARD], actorId: $operator->identifier());
                $this->events->publish('org.queue.deleted', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $q['id']]);
                $deletedQueues[] = $q['id'];
            }
            if (is_string($replacementRg)) {
                $this->queues->update($replacementRg, ['is_rg' => true], $operator->identifier());
            }
            DB::table('departments')->where('id', $departmentId)->update(['is_active' => false, 'deleted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('org.department.deleted', 'department', $departmentId, AuditActor::Staff, [
                'department_id' => $departmentId, 'replacement_rg_queue_id' => $replacementRg, 'moved_to_queue_id' => $target, 'moved_count' => $moved['moved'],
                'rule_choices' => $choices['rules'] ?? [], 'bot_choices' => $choices['bots'] ?? [], 'sla_choices' => $choices['sla_policies'] ?? [],
            ], actorId: $operator->identifier());
            $this->events->publish('org.department.deleted', ['tenant_id' => $this->context->tenantId(), 'department_id' => $departmentId]);
        });

        return $moved + ['deleted_department_id' => $departmentId, 'deleted_queue_ids' => $deletedQueues];
    }
}
