<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rules;

use App\Domain\Automation\Actions\ActionRegistry;
use App\Domain\Automation\Engine\RuleCompiler;
use App\Domain\Automation\Engine\TriggerMap;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-01 / FR-07 / BR-01 / SEC-01 / SEC-06 / R-04-adjacent — the ONE door
 * for rules.
 *
 * FR-07's split is real here: every content change lands as a DRAFT REVISION
 * (INSERT-only history, the fill-only activation trigger); activate() captures
 * the SEC-01 floor from the ACTIVATOR'S live map, swaps the rule's pointer
 * ATOMICALLY, and invalidates the compiled cache. The controller layer holds
 * the two distinct keys (automation.rules.edit vs .activate — Doc 03's, both
 * verified present at step 0); this writer takes the ACTOR because the floor
 * is his. A SEC-01 floor refresh = re-activate (a NEW revision, fresh capture).
 * Rule activation/deactivation are SEC-06 high-risk audits ([D17] classes).
 *
 * schedule triggers: min interval 5 min (FR-01), next-run derived by the
 * scheduler sweep (step 5) into schedule_next_run_at (EC-10's recompute target).
 */
final class RuleWriter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly RuleCompiler $compiler,
        private readonly SystemActorFloor $floor,
    ) {}

    /**
     * @param  array{name: string, description?: ?string, trigger: string, conditions?: array<string, mixed>, actions?: list<array<string, mixed>>, run_window?: ?array<string, mixed>, schedule_cron?: ?string, cooldown_seconds?: int}  $definition
     * @return array{rule_id: string, revision_id: string}
     */
    public function create(array $definition, User $author): array
    {
        $this->assertDefinition($definition);
        $tenantId = $this->context->tenantId();
        $ruleId = (string) Str::uuid7();
        $max = DB::table('automation_rules')->max('position');
        $position = (is_numeric($max) ? (int) $max : 0) + 1;

        $revisionId = DB::transaction(function () use ($tenantId, $ruleId, $definition, $position, $author): string {
            DB::table('automation_rules')->insert([
                'id' => $ruleId, 'tenant_id' => $tenantId,
                'name' => $definition['name'], 'description' => $definition['description'] ?? null,
                'trigger' => $definition['trigger'], 'position' => $position,
                'schedule_cron' => $definition['schedule_cron'] ?? null,
                'run_window' => isset($definition['run_window']) ? json_encode($definition['run_window'], JSON_THROW_ON_ERROR) : null,
                'cooldown_seconds' => $definition['cooldown_seconds'] ?? 300,
            ]);
            $revisionId = $this->draftRevision($ruleId, $definition, $author);
            $this->announce($ruleId, $revisionId, 'created', $author);

            return $revisionId;
        });

        return ['rule_id' => $ruleId, 'revision_id' => $revisionId];
    }

    /**
     * FR-07: an edit NEVER mutates the active revision — it drafts a new one.
     *
     * @param  array{name?: string, description?: ?string, trigger?: string, conditions?: array<string, mixed>, actions?: list<array<string, mixed>>, run_window?: ?array<string, mixed>, schedule_cron?: ?string, cooldown_seconds?: int}  $changes
     */
    public function edit(string $ruleId, array $changes, User $author): string
    {
        $rule = $this->row($ruleId);
        $current = $this->currentDefinition($ruleId);
        $definition = array_merge($current, $changes);
        $this->assertDefinition($definition);

        return DB::transaction(function () use ($ruleId, $rule, $definition, $changes, $author): string {
            $head = array_intersect_key($changes, array_flip(['name', 'description', 'trigger', 'schedule_cron', 'cooldown_seconds']));
            if (isset($changes['run_window'])) {
                $head['run_window'] = json_encode($changes['run_window'], JSON_THROW_ON_ERROR);
            }
            if ($head !== []) {
                $head['updated_at'] = DB::raw('clock_timestamp()');
                DB::table('automation_rules')->where('id', $ruleId)->update($head);
            }
            $revisionId = $this->draftRevision($ruleId, $definition, $author);
            $this->announce($ruleId, $revisionId, 'updated', $author);
            if ($rule->active) {
                $this->compiler->invalidate($rule->trigger); // head edits (cooldown, position) bite immediately
            }

            return $revisionId;
        });
    }

    /** FR-07: activation swaps atomically; SEC-01: the floor is captured HERE, from the ACTIVATOR. */
    public function activate(string $ruleId, string $revisionId, User $activator): void
    {
        $rule = $this->row($ruleId);
        $revision = DB::table('automation_rule_revisions')->where('id', $revisionId)->where('rule_id', $ruleId)->first();
        if ($revision === null) {
            throw new InvalidArgumentException('unknown revision for this rule');
        }
        DB::transaction(function () use ($ruleId, $revisionId, $activator): void {
            DB::table('automation_rule_revisions')->where('id', $revisionId)->update([
                'activated_by' => $activator->identifier(),
                'activator_capability_floor' => json_encode($this->floor->captureFor($activator), JSON_THROW_ON_ERROR),
            ]);
            DB::table('automation_rules')->where('id', $ruleId)->update([
                'active_revision_id' => $revisionId, 'active' => true,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->announce($ruleId, $revisionId, 'activated', $activator);
        });
        $this->compiler->invalidate($rule->trigger);
    }

    public function deactivate(string $ruleId, User $actor): void
    {
        $rule = $this->row($ruleId);
        DB::transaction(function () use ($ruleId, $rule, $actor): void {
            DB::table('automation_rules')->where('id', $ruleId)->update(['active' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->announce($ruleId, is_string($rule->active_revision_id) ? $rule->active_revision_id : null, 'deactivated', $actor);
        });
        $this->compiler->invalidate($rule->trigger);
    }

    /** @param array<string, mixed> $definition */
    private function assertDefinition(array $definition): void
    {
        $trigger = is_string($definition['trigger'] ?? null) ? $definition['trigger'] : '(none)';
        if (! array_key_exists($trigger, TriggerMap::EVENTS)) {
            throw new InvalidArgumentException(sprintf('unknown trigger "%s" (FR-01)', $trigger));
        }
        if ($definition['trigger'] === 'schedule') {
            $cron = $definition['schedule_cron'] ?? null;
            if (! is_string($cron) || $cron === '') {
                throw new InvalidArgumentException('a schedule rule needs schedule_cron (FR-01)');
            }
            // FR-01: min interval 5 minutes — a minute-field step below 5 (or every-minute) is refused.
            $minute = explode(' ', trim($cron))[0];
            if ($minute === '*' || (preg_match('~^\*/([0-9]+)$~', $minute, $m) === 1 && (int) $m[1] < 5)) {
                throw new InvalidArgumentException('schedule interval below the 5-minute floor (FR-01)');
            }
        }
        $actions = is_array($definition['actions'] ?? null) ? $definition['actions'] : [];
        foreach ($actions as $action) {
            $type = is_array($action) && is_string($action['type'] ?? null) ? $action['type'] : '';
            if (! in_array($type, ActionRegistry::TYPES, true)) {
                throw new InvalidArgumentException(sprintf('unknown action type "%s" — the registry is closed (FR-03)', $type));
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function draftRevision(string $ruleId, array $definition, User $author): string
    {
        $revisionId = (string) Str::uuid7();
        DB::table('automation_rule_revisions')->insert([
            'id' => $revisionId, 'tenant_id' => $this->context->tenantId(), 'rule_id' => $ruleId,
            'definition' => json_encode([
                'trigger' => $definition['trigger'],
                'conditions' => $definition['conditions'] ?? ['all' => []],
                'actions' => $definition['actions'] ?? [],
                'run_window' => $definition['run_window'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'author_id' => $author->identifier(),
        ]);

        return $revisionId;
    }

    /** @return array<string, mixed> */
    private function currentDefinition(string $ruleId): array
    {
        $latest = DB::table('automation_rule_revisions')->where('rule_id', $ruleId)->orderByDesc('created_at')->orderByDesc('id')->value('definition');
        $rule = $this->row($ruleId);
        /** @var array<string, mixed> $definition */
        $definition = is_string($latest) ? json_decode($latest, true, 32, JSON_THROW_ON_ERROR) : [];

        return array_merge($definition, [
            'name' => $rule->name, 'trigger' => $rule->trigger,
            'schedule_cron' => $rule->schedule_cron, 'cooldown_seconds' => $rule->cooldown_seconds,
        ]);
    }

    private function announce(string $ruleId, ?string $revisionId, string $kind, User $actor): void
    {
        $this->events->publish('automation.rule.changed', [
            'tenant_id' => $this->context->tenantId(), 'rule_id' => $ruleId,
            'revision_id' => $revisionId, 'kind' => $kind,
        ]);
        // activated/deactivated are the SEC-06 high-risk pair ([D17] visibility classes).
        $this->audit->record('automation.rule.'.$kind, 'automation_rule', $ruleId, AuditActor::Staff, ['rule_id' => $ruleId, 'revision_id' => $revisionId, 'kind' => $kind], actorId: $actor->identifier());
    }

    /** @return object{id: string, name: string, trigger: string, active: bool, active_revision_id: ?string, schedule_cron: ?string, cooldown_seconds: int} */
    private function row(string $ruleId): object
    {
        /** @var object{id: string, name: string, trigger: string, active: bool, active_revision_id: ?string, schedule_cron: ?string, cooldown_seconds: int}|null $rule */
        $rule = DB::table('automation_rules')->where('id', $ruleId)->first();
        if ($rule === null) {
            throw new InvalidArgumentException('unknown rule');
        }

        return $rule;
    }
}
