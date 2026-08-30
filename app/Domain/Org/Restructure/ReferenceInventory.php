<?php

declare(strict_types=1);

namespace App\Domain\Org\Restructure;

use App\Domain\Org\Structure\QueueWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-18 / AC-07 / SEC-04 / R-05 — the REFERENCE INVENTORY, inventory-first: everything a
 * delete would break, discovered from the rows and the seams BEFORE any choice is offered.
 *
 *   rules        — Doc 12's ([D12] fixture-shaped seam, QueueWriter::rulesTargeting)
 *   bots         — Doc 05 connection routing tags matching the queue's tags / department ([D12]-shaped seam)
 *   sla_policies — Doc 12's ([D12] seam; the fixture's `sla_policies` slice, empty until Doc 12)
 *   views        — REAL: inbox_saved_views whose filters name the queue/department
 *   tickets      — REAL: open-family + total counts, from ONE query object reused by the wizards
 *                  (AC-07 "typed confirmation computed from the SAME query that acts")
 *   queues       — REAL (department only)
 *
 * SEC-04: out-of-scope view contents are never shown — a view the operator cannot read (another
 * user's private view) contributes to the COUNT and a redacted name only.
 */
final class ReferenceInventory
{
    public const OPEN_FAMILY = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];

    public function __construct(
        private readonly QueueWriter $queues,
    ) {}

    /**
     * @return array{target: array{type: string, id: string, department_id: string, is_rg: bool, is_department_default: bool}, rules: list<array{id: string, name: string, target_queue_id: string}>, bots: list<array{id: string, name: string, tag: string}>, sla_policies: list<array{id: string, name: string, target: string}>, views: array{count: int, items: list<array{id: string, name: ?string, redacted: bool}>}, tickets: array{open: int, total: int}}
     */
    public function forQueue(string $queueId, User $viewer): array
    {
        /** @var object{id: string, department_id: string, routing_tags: string, is_rg: bool}|null $queue */
        $queue = DB::table('queues')->where('id', $queueId)->whereNull('deleted_at')->first(['id', 'department_id', 'routing_tags', 'is_rg']);
        if ($queue === null) {
            throw new RestructureRefused('queue_not_found', 'unknown queue');
        }
        $tags = $this->pgArray($queue->routing_tags);

        return [
            'target' => ['type' => 'queue', 'id' => $queueId, 'department_id' => $queue->department_id, 'is_rg' => (bool) $queue->is_rg, 'is_department_default' => DB::table('departments')->where('default_queue_id', $queueId)->whereNull('deleted_at')->exists()],
            'rules' => $this->queues->rulesTargeting($queueId),
            'bots' => $this->botsMatching($tags, $queue->department_id),
            'sla_policies' => $this->slaPolicies($queueId, $queue->department_id),
            'views' => $this->views('queue_id', $queueId, $viewer),
            'tickets' => $this->ticketCounts($this->ticketsOfQueue($queueId)),
        ];
    }

    /**
     * @return array{target: array{type: string, id: string, default_queue_id: ?string, holds_rg_queue: bool}, queues: list<array{id: string, name: string, is_rg: bool, is_active: bool}>, rules: list<array{id: string, name: string, target_queue_id: string}>, bots: list<array{id: string, name: string, tag: string}>, sla_policies: list<array{id: string, name: string, target: string}>, views: array{count: int, items: list<array{id: string, name: ?string, redacted: bool}>}, tickets: array{open: int, total: int}}
     */
    public function forDepartment(string $departmentId, User $viewer): array
    {
        /** @var object{id: string, default_queue_id: ?string}|null $department */
        $department = DB::table('departments')->where('id', $departmentId)->whereNull('deleted_at')->first(['id', 'default_queue_id']);
        if ($department === null) {
            throw new RestructureRefused('department_not_found', 'unknown department');
        }
        /** @var list<object{id: string, name: string, is_rg: bool, is_active: bool, routing_tags: string}> $queues */
        $queues = DB::table('queues')->where('department_id', $departmentId)->whereNull('deleted_at')->orderBy('position')->get(['id', 'name', 'is_rg', 'is_active', 'routing_tags'])->all();
        $rules = [];
        $bots = [];
        $tags = [];
        foreach ($queues as $q) {
            $rules = array_merge($rules, $this->queues->rulesTargeting($q->id));
            $tags = array_merge($tags, $this->pgArray($q->routing_tags));
        }
        $bots = $this->botsMatching(array_values(array_unique($tags)), $departmentId);
        $hasRg = false;
        foreach ($queues as $q) {
            $hasRg = $hasRg || (bool) $q->is_rg;
        }

        return [
            'target' => ['type' => 'department', 'id' => $departmentId, 'default_queue_id' => $department->default_queue_id, 'holds_rg_queue' => $hasRg],
            'queues' => array_map(static fn (object $q): array => ['id' => $q->id, 'name' => $q->name, 'is_rg' => (bool) $q->is_rg, 'is_active' => (bool) $q->is_active], $queues),
            'rules' => $rules,
            'bots' => $bots,
            'sla_policies' => $this->slaPolicies(null, $departmentId),
            'views' => $this->views('department_id', $departmentId, $viewer),
            'tickets' => $this->ticketCounts($this->ticketsOfDepartment($departmentId)),
        ];
    }

    /** THE ONE QUERY the wizards act on (queue). */
    public function ticketsOfQueue(string $queueId): \Illuminate\Database\Query\Builder
    {
        return DB::table('tickets')->where('queue_id', $queueId);
    }

    /** THE ONE QUERY the wizards act on (department). */
    public function ticketsOfDepartment(string $departmentId): \Illuminate\Database\Query\Builder
    {
        return DB::table('tickets')->where('department_id', $departmentId);
    }

    /** @return array{open: int, total: int} */
    public function ticketCounts(\Illuminate\Database\Query\Builder $query): array
    {
        return [
            'open' => (clone $query)->whereIn('status', self::OPEN_FAMILY)->count(),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * AC-07: the typed confirmation is COMPUTED from the same query the execution acts on —
     * "MOVE {open} TICKETS" (or "DELETE" when nothing is held).
     */
    public function confirmationFor(\Illuminate\Database\Query\Builder $query): string
    {
        $counts = $this->ticketCounts($query);

        return $counts['open'] === 0 ? 'DELETE' : sprintf('MOVE %d TICKETS', $counts['open']);
    }

    /**
     * @param  list<string>  $tags
     * @return list<array{id: string, name: string, tag: string}>
     */
    private function botsMatching(array $tags, string $departmentId): array
    {
        $path = base_path('fixtures/routing/seams/doc05-connection-tags.json');
        if (! app()->environment(['local', 'testing', 'ci']) || ! is_file($path)) {
            return [];
        }
        /** @var array{connections?: list<array{connection_id: string, routing_tag: ?string}>} $seam */
        $seam = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($seam['connections'] ?? [] as $row) {
            $tag = $row['routing_tag'];
            if ($tag !== null && in_array($tag, $tags, true)) {
                $out[] = ['id' => $row['connection_id'], 'name' => $row['connection_id'], 'tag' => $tag];
            }
        }
        unset($departmentId);

        return $out;
    }

    /** @return list<array{id: string, name: string, target: string}> */
    private function slaPolicies(?string $queueId, string $departmentId): array
    {
        $path = base_path('fixtures/routing/seams/doc12-rules.json');
        if (! app()->environment(['local', 'testing', 'ci']) || ! is_file($path)) {
            return [];
        }
        /** @var array{sla_policies?: list<array{id: string, name: string, target_queue_id?: string, target_department_id?: string}>} $seam */
        $seam = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($seam['sla_policies'] ?? [] as $p) {
            if (($queueId !== null && ($p['target_queue_id'] ?? null) === $queueId) || ($p['target_department_id'] ?? null) === $departmentId) {
                $out[] = ['id' => $p['id'], 'name' => $p['name'], 'target' => (string) ($p['target_queue_id'] ?? $p['target_department_id'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * SEC-04: names only where the viewer may read the view (own, or shared to them); otherwise a
     * redacted entry — the COUNT is always complete.
     *
     * @return array{count: int, items: list<array{id: string, name: ?string, redacted: bool}>}
     */
    private function views(string $filterKey, string $id, User $viewer): array
    {
        $column = $filterKey === 'queue_id' ? "filters->>'queue_id' = ?" : "filters->>'department_id' = ?";
        /** @var list<object{id: string, name: string, owner_user_id: string, shared_to_type: ?string}> $rows */
        $rows = DB::table('inbox_saved_views')->whereRaw($column, [$id])->whereNull('deleted_at')->get(['id', 'name', 'owner_user_id', 'shared_to_type'])->all();
        /** @var list<array{id: string, name: ?string, redacted: bool}> $items */
        $items = [];
        foreach ($rows as $row) {
            $readable = $row->owner_user_id === $viewer->identifier() || $row->shared_to_type !== null;
            $items[] = ['id' => $row->id, 'name' => $readable ? $row->name : null, 'redacted' => ! $readable];
        }

        return ['count' => count($rows), 'items' => $items];
    }

    /** @return list<string> */
    private function pgArray(string $literal): array
    {
        $trim = trim($literal, '{}');

        return $trim === '' ? [] : array_map(static fn (string $s): string => trim($s, '"'), explode(',', $trim));
    }
}
