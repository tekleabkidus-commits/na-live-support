<?php

declare(strict_types=1);

namespace App\Domain\Org\Structure;

use App\Domain\Org\OrgRefused;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-03 / FR-09..FR-12 / FR-18 / EC-05 / EC-10 / BR-01 — queues as operator content.
 *
 * - priority_rules is the ORDERED boost list; the starvation cap is NEVER an element
 *   (BR-01 / FLAG B): a rule named `starvation` is refused at this door.
 * - is_rg at most one per tenant is DB physics (partial unique) — surfaced as a refusal code.
 * - EC-10: deactivating a queue that is its department's default is refused (a defaultless
 *   department cannot be configured); deactivation lists the rules that fall to the default
 *   (a [D12] fixture-shaped inventory until Doc 12 exists).
 * - EC-05: a VIP boost whose source is an agent-editable TAG is allowed but WARNED
 *   (`vip_source_warning` in the returned warnings — the config screen shows it).
 * - Membership rows END (ended_at, FLAG C); via-team links are queue_teams rows.
 * - DELETE is the wizard's (step 10) — never here.
 */
final class QueueWriter
{
    public const STRATEGIES = ['round_robin', 'load_based', 'skill_based'];

    public const BOOSTS = ['urgent_priority', 'vip', 'window_closing', 'sla_at_risk', 'reopened'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $departmentId, string $name, string $actorId, string $strategy = 'round_robin'): string
    {
        if (! DB::table('departments')->where('id', $departmentId)->whereNull('deleted_at')->exists()) {
            throw new OrgRefused('department_not_found', 'a queue belongs to exactly one department (FR-03).');
        }
        $this->assertStrategy($strategy);
        $id = (string) Str::uuid7();
        $max = DB::table('queues')->where('department_id', $departmentId)->whereNull('deleted_at')->max('position');
        $position = (is_numeric($max) ? (int) $max : 0) + 1;
        DB::transaction(function () use ($id, $departmentId, $name, $strategy, $position, $actorId): void {
            DB::table('queues')->insert(['id' => $id, 'tenant_id' => $this->context->tenantId(), 'department_id' => $departmentId, 'name' => $name, 'position' => $position, 'assignment_strategy' => $strategy]);
            $this->events->publish('org.queue.created', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $id]);
            $this->audit->record('org.queue.created', 'queue', $id, AuditActor::Staff, ['queue_id' => $id, 'department_id' => $departmentId, 'assignment_strategy' => $strategy], actorId: $actorId);
        });

        return $id;
    }

    /**
     * @param  array<string, mixed>  $changes  name|position|assignment_strategy|capacity_per_agent|priority_rules|skill_matchers|skill_fallback|routing_tags|is_rg|is_active
     * @return list<string> warnings (EC-05 `vip_source_warning`)
     */
    public function update(string $queueId, array $changes, string $actorId): array
    {
        $queue = $this->live($queueId);
        $update = [];
        $warnings = [];
        $audits = [];

        if (array_key_exists('name', $changes)) {
            if (! is_string($changes['name']) || trim($changes['name']) === '') {
                throw new OrgRefused('queue_name_required', 'a queue needs a name (FR-03).');
            }
            $update['name'] = $changes['name'];
        }
        if (array_key_exists('capacity_per_agent', $changes)) {
            $cap = $changes['capacity_per_agent'];
            if ($cap !== null && (! is_int($cap) || $cap < 1)) {
                throw new OrgRefused('queue_capacity_invalid', 'capacity_per_agent is a positive integer or null (FR-03).');
            }
            $update['capacity_per_agent'] = $cap;
        }
        if (array_key_exists('assignment_strategy', $changes)) {
            $this->assertStrategy(is_string($changes['assignment_strategy']) ? $changes['assignment_strategy'] : '');
            $update['assignment_strategy'] = $changes['assignment_strategy'];
            $audits[] = ['org.queue.strategy_changed', ['queue_id' => $queueId, 'old_strategy' => $queue->assignment_strategy, 'new_strategy' => $changes['assignment_strategy']]];
        }
        if (array_key_exists('priority_rules', $changes)) {
            $rules = $changes['priority_rules'];
            if (! is_array($rules)) {
                throw new OrgRefused('queue_boosts_invalid', 'priority_rules is an ordered list (FR-12).');
            }
            foreach ($rules as $rule) {
                if (! is_string($rule) || ! in_array($rule, self::BOOSTS, true)) {
                    // BR-01 / FLAG B: the cap is not a boost and cannot be placed in the list.
                    throw new OrgRefused('queue_boost_unknown', sprintf('unknown boost %s — the starvation cap is never a list element (BR-01).', is_string($rule) ? $rule : gettype($rule)));
                }
            }
            if (count($rules) !== count(array_unique($rules))) {
                throw new OrgRefused('queue_boosts_duplicate', 'a boost appears once (FR-12).');
            }
            if (in_array('vip', $rules, true) && ($changes['vip_source'] ?? 'field') === 'tag') {
                $warnings[] = 'vip_source_warning'; // EC-05
            }
            $update['priority_rules'] = json_encode(array_values($rules), JSON_THROW_ON_ERROR);
            $audits[] = ['org.queue.boosts_changed', ['queue_id' => $queueId, 'old_rules' => json_decode((string) $queue->priority_rules, true), 'new_rules' => array_values($rules)]];
        }
        if (array_key_exists('skill_matchers', $changes)) {
            $update['skill_matchers'] = json_encode(array_values((array) $changes['skill_matchers']), JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('skill_fallback', $changes)) {
            if (! in_array($changes['skill_fallback'], ['nearest_skill', 'wait'], true)) {
                throw new OrgRefused('queue_fallback_invalid', 'skill_fallback is nearest_skill or wait (FR-11).');
            }
            $update['skill_fallback'] = $changes['skill_fallback'];
        }
        if (array_key_exists('routing_tags', $changes)) {
            $tags = [];
            foreach ((array) $changes['routing_tags'] as $tag) {
                if (! is_string($tag) || trim($tag) === '') {
                    throw new OrgRefused('queue_tag_invalid', 'routing tags are non-empty strings (FR-03).');
                }
                $tags[] = $tag;
            }
            $tags = array_values(array_unique($tags));
            $update['routing_tags'] = '{'.implode(',', array_map(fn ($t) => '"'.str_replace('"', '\\"', $t).'"', $tags)).'}';
            $audits[] = ['org.queue.tags_changed', ['queue_id' => $queueId, 'old_tags' => $queue->routing_tags, 'new_tags' => $tags]];
        }
        if (array_key_exists('is_rg', $changes)) {
            $update['is_rg'] = (bool) $changes['is_rg'];
        }
        if (array_key_exists('position', $changes)) {
            if (! is_int($changes['position'])) {
                throw new OrgRefused('queue_position_invalid', 'position is an integer (§8).');
            }
            $update['position'] = $changes['position'];
            $audits[] = ['org.queue.reordered', ['queue_id' => $queueId, 'old_position' => (int) $queue->position, 'new_position' => $changes['position']]];
        }
        if (array_key_exists('is_active', $changes)) {
            if ($changes['is_active'] === false) {
                $this->assertNotDepartmentDefault($queueId);
            }
            $update['is_active'] = (bool) $changes['is_active'];
        }
        if ($update === []) {
            return $warnings;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');

        try {
            DB::transaction(function () use ($queueId, $update, $changes, $audits, $actorId): void {
                DB::table('queues')->where('id', $queueId)->update($update);
                $this->events->publish('org.queue.updated', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'changed' => array_keys($changes)]);
                $this->audit->record('org.queue.updated', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'changed' => array_keys($changes)], actorId: $actorId);
                foreach ($audits as [$key, $payload]) {
                    $this->audit->record($key, 'queue', $queueId, AuditActor::Staff, $payload, actorId: $actorId);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'queues_one_rg_per_tenant')) {
                throw new OrgRefused('queue_rg_already_exists', 'this tenant already has its responsible-gaming queue — at most one (FR-03).');
            }
            throw $e;
        }

        return $warnings;
    }

    /**
     * FR-18: deactivate stops intake; the rules targeting it fall to the department default — listed.
     *
     * @return list<array{id: string, name: string, target_queue_id: string}>
     */
    public function deactivate(string $queueId, string $actorId): array
    {
        $this->live($queueId);
        $this->assertNotDepartmentDefault($queueId);
        $falling = $this->rulesTargeting($queueId);   // [D12] fixture-shaped inventory
        DB::transaction(function () use ($queueId, $falling, $actorId): void {
            DB::table('queues')->where('id', $queueId)->update(['is_active' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->events->publish('org.queue.updated', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'changed' => ['is_active']]);
            $this->audit->record('org.queue.deactivated', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'rules_falling_to_default' => $falling], actorId: $actorId);
        });

        return $falling;
    }

    public function addMember(string $queueId, string $userId, string $actorId): void
    {
        $this->live($queueId);
        DB::transaction(function () use ($queueId, $userId, $actorId): void {
            $live = DB::table('queue_members')->where('queue_id', $queueId)->where('user_id', $userId)->whereNull('ended_at')->exists();
            if (! $live) {
                DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'kind' => 'direct', 'granted_by' => $actorId]);
            }
            $this->audit->record('org.queue.member_added', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'user_id' => $userId, 'via' => 'direct'], actorId: $actorId);
            $this->events->publish('queue.membership_changed', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'added', 'via' => 'direct']);
        });
    }

    /** FLAG C: a direct membership ENDS (ended_at), never deleted. */
    public function removeMember(string $queueId, string $userId, string $actorId): void
    {
        $this->live($queueId);
        DB::transaction(function () use ($queueId, $userId, $actorId): void {
            DB::table('queue_members')->where('queue_id', $queueId)->where('user_id', $userId)->where('kind', 'direct')->whereNull('ended_at')
                ->update(['ended_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('org.queue.member_removed', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'user_id' => $userId, 'via' => 'direct'], actorId: $actorId);
            $this->events->publish('queue.membership_changed', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'removed', 'via' => 'direct']);
        });
    }

    public function addTeam(string $queueId, string $teamId, string $actorId): void
    {
        $this->live($queueId);
        if (! DB::table('teams')->where('id', $teamId)->whereNull('deleted_at')->exists()) {
            throw new OrgRefused('team_not_found', 'unknown team');
        }
        DB::transaction(function () use ($queueId, $teamId, $actorId): void {
            if (! DB::table('queue_teams')->where('queue_id', $queueId)->where('team_id', $teamId)->exists()) {
                DB::table('queue_teams')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'team_id' => $teamId]);
            }
            /** @var list<string> $members */
            $members = DB::table('team_members')->where('team_id', $teamId)->pluck('user_id')->all();
            foreach ($members as $userId) {
                $this->audit->record('org.queue.member_added', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'user_id' => $userId, 'via' => 'team'], actorId: $actorId);
                $this->events->publish('queue.membership_changed', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'added', 'via' => 'team']);
            }
        });
    }

    public function removeTeam(string $queueId, string $teamId, string $actorId): void
    {
        $this->live($queueId);
        DB::transaction(function () use ($queueId, $teamId, $actorId): void {
            DB::table('queue_teams')->where('queue_id', $queueId)->where('team_id', $teamId)->delete();
            /** @var list<string> $members */
            $members = DB::table('team_members')->where('team_id', $teamId)->pluck('user_id')->all();
            foreach ($members as $userId) {
                $this->audit->record('org.queue.member_removed', 'queue', $queueId, AuditActor::Staff, ['queue_id' => $queueId, 'user_id' => $userId, 'via' => 'team'], actorId: $actorId);
                $this->events->publish('queue.membership_changed', ['tenant_id' => $this->context->tenantId(), 'queue_id' => $queueId, 'user_id' => $userId, 'change' => 'removed', 'via' => 'team']);
            }
        });
    }

    /**
     * [D12] seam: the automation rules targeting a queue — fixture-shaped until Doc 12 exists.
     *
     * @return list<array{id: string, name: string, target_queue_id: string}>
     */
    public function rulesTargeting(string $queueId): array
    {
        $path = base_path('fixtures/routing/seams/doc12-rules.json');
        if (! app()->environment(['local', 'testing', 'ci']) || ! is_file($path)) {
            return [];
        }
        /** @var array{rules?: list<array{id: string, name: string, target_queue_id: string}>} $seam */
        $seam = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);

        return array_values(array_filter($seam['rules'] ?? [], fn (array $r): bool => $r['target_queue_id'] === $queueId));
    }

    private function assertStrategy(string $strategy): void
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new OrgRefused('queue_strategy_invalid', 'assignment_strategy is round_robin, load_based or skill_based (FR-03).');
        }
    }

    /** EC-10: a department's default queue cannot be switched off — pick another default first. */
    private function assertNotDepartmentDefault(string $queueId): void
    {
        if (DB::table('departments')->where('default_queue_id', $queueId)->whereNull('deleted_at')->exists()) {
            throw new OrgRefused('queue_is_department_default', 'this queue is its department\'s default — choose another default before deactivating it (EC-10).');
        }
    }

    /** @return object{id: string, assignment_strategy: string, priority_rules: string, routing_tags: string, position: int|string} */
    private function live(string $queueId): object
    {
        /** @var object{id: string, assignment_strategy: string, priority_rules: string, routing_tags: string, position: int|string}|null $row */
        $row = DB::table('queues')->where('id', $queueId)->whereNull('deleted_at')->first(['id', 'assignment_strategy', 'priority_rules', 'routing_tags', 'position']);
        if ($row === null) {
            throw new OrgRefused('queue_not_found', 'unknown queue');
        }

        return $row;
    }
}
