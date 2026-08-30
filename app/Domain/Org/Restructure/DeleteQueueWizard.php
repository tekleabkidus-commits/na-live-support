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
 * Doc 11 FR-18 "Delete queue: requires empty-or-reassign choice (bulk move to another queue with
 * progress); Doc 07 EC-09 view behavior follows" / AC-07 / SEC-04 / EC-06 / EC-10.
 *
 * preview() = the inventory + the per-reference choices the operator must make + the typed
 * confirmation COMPUTED from the same query execute() acts on. execute() RECOMPUTES that phrase
 * from the same query and refuses `confirmation_stale` when the operator typed a phrase for a
 * count that is no longer true (sabotage #14's assertion) — then moves (ChunkedMover, per-chunk
 * live rights), redirects the rules/bots/SLA references by recording the operator's choices
 * (their tables are Doc 12/05's — the choices ride the audit until those documents land),
 * TOMBSTONES the queue (deleted_at, is_active false — never a row delete) and publishes
 * `org.queue.deleted` (the orphan net, FR-16c, is the terminal safety for anything still naming it).
 * A department's DEFAULT queue cannot be deleted (EC-10) — pick another default first; the RG queue
 * cannot be deleted without a replacement RG target (FR-01's contract with Doc 08/09).
 */
final class DeleteQueueWizard
{
    public const WIZARD = 'delete_queue';

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
    public function preview(string $queueId, User $operator): array
    {
        $inv = $this->inventory->forQueue($queueId, $operator);
        $confirmation = $this->inventory->confirmationFor($this->inventory->ticketsOfQueue($queueId));

        return $inv + [
            'choices' => [
                'target_queue_id' => ['required' => $inv['tickets']['total'] > 0, 'description' => 'the live queue every ticket moves to'],
                'rules' => array_map(static fn (array $r): array => ['id' => $r['id'], 'new_target_queue_id' => null], $inv['rules']),
                'bots' => array_map(static fn (array $b): array => ['id' => $b['id'], 'new_target_queue_id' => null], $inv['bots']),
                'sla_policies' => array_map(static fn (array $p): array => ['id' => $p['id'], 'new_target_queue_id' => null], $inv['sla_policies']),
                'replacement_rg_queue_id' => ['required' => (bool) $inv['target']['is_rg']],
            ],
            'confirmation' => $confirmation,
            'blocked' => $inv['target']['is_department_default'] ? 'queue_is_department_default' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $choices
     * @param  callable(int, int): void|null  $betweenChunks
     * @return array{moved: int, chunks: int, deleted_queue_id: string}
     */
    public function execute(string $queueId, array $choices, string $typedConfirmation, User $operator, ?callable $betweenChunks = null): array
    {
        if (! $this->maps->verdictFor($operator, 'org.queues.manage')->allowed) {
            throw new RestructureRefused('forbidden', 'org.queues.manage required');
        }
        $inv = $this->inventory->forQueue($queueId, $operator);
        if ($inv['target']['is_department_default']) {
            throw new RestructureRefused('queue_is_department_default', 'choose another department default before deleting this queue (EC-10)');
        }
        // AC-07 / sabotage #14: the SAME query, recomputed NOW — never the preview's number.
        $expected = $this->inventory->confirmationFor($this->inventory->ticketsOfQueue($queueId));
        if ($typedConfirmation !== $expected) {
            throw new RestructureRefused('confirmation_stale', sprintf('the typed confirmation does not match the live count (%s)', $expected));
        }
        $targetRaw = $choices['target_queue_id'] ?? null;
        $target = is_string($targetRaw) ? $targetRaw : null;
        $total = $inv['tickets']['total'];
        if ($total > 0 && $target === null) {
            throw new RestructureRefused('target_queue_required', 'this queue holds tickets — choose the queue they move to (FR-18 empty-or-reassign)');
        }
        if ($target !== null && $target === $queueId) {
            throw new RestructureRefused('target_queue_invalid', 'a queue cannot receive its own tickets');
        }
        $replacementRg = $choices['replacement_rg_queue_id'] ?? null;
        if ($inv['target']['is_rg'] && ! is_string($replacementRg)) {
            throw new RestructureRefused('replacement_rg_required', 'this is the RG queue — a replacement RG target queue is required (FR-01; Doc 08/09 route to it)');
        }
        foreach ($inv['rules'] as $rule) {
            $choice = null;
            foreach ((array) ($choices['rules'] ?? []) as $c) {
                if (is_array($c) && ($c['id'] ?? null) === $rule['id']) {
                    $choice = $c['new_target_queue_id'] ?? null;
                }
            }
            if (! is_string($choice)) {
                throw new RestructureRefused('rule_choice_required', sprintf('rule "%s" targets this queue — choose where it goes (R-05: no silent breakage)', $rule['name']));
            }
        }

        $moved = ['moved' => 0, 'chunks' => 0];
        if ($target !== null) {   // a target with nothing to move is a no-op move (the counts were checked above)
            $moved = $this->mover->move(self::WIZARD, $queueId, $this->inventory->ticketsOfQueue($queueId), $target, $operator, 'org.queues.manage', $betweenChunks);
        }

        DB::transaction(function () use ($queueId, $target, $moved, $replacementRg, $choices, $operator): void {
            DB::table('queues')->where('id', $queueId)->update(['is_active' => false, 'is_rg' => false, 'deleted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            if (is_string($replacementRg)) {
                $this->queues->update($replacementRg, ['is_rg' => true], $operator->identifier());   // the partial unique is free now
            }
            $this->audit->record('org.queue.deleted', 'queue', $queueId, AuditActor::Staff, [
                'queue_id' => $queueId, 'moved_to_queue_id' => $target, 'moved_count' => $moved['moved'],
                'rule_choices' => $choices['rules'] ?? [], 'bot_choices' => $choices['bots'] ?? [], 'sla_choices' => $choices['sla_policies'] ?? [], 'replacement_rg_queue_id' => $replacementRg,
            ], actorId: $operator->identifier());
            $this->events->publish('org.queue.deleted', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId]);
        });

        return $moved + ['deleted_queue_id' => $queueId];
    }
}
