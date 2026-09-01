<?php

declare(strict_types=1);

namespace App\Domain\Sla\Policies;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-08 / BR-01 / BR-03 / SEC-06 — the ONE door for SLA policies.
 *
 * First-match wins by (position, id) — BR-01's determinism vocabulary. Edits
 * are PROSPECTIVE by physics: armed instances embed their snapshots and hold
 * no UPDATE grant (BR-03) — this writer touches policies only, never
 * instances, and SnapshotImmutabilityTest falsifies the reverse. Policy
 * changes are the SEC-06 high-risk class ([D17]).
 *
 * The match-conditions vocabulary is FR-08's SUBSET of FR-02 — department_id,
 * priority, channel, tags, vip_source — validated closed here (a queue is
 * deliberately NOT matchable: FR-08 names none).
 */
final class PolicyWriter
{
    private const MATCH_KEYS = ['department_id', 'priority', 'channel', 'tags', 'vip_source'];

    private const STAGES = ['first_response_s', 'next_response_s', 'resolution_s'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, match_conditions?: array<string, mixed>, targets: array<string, array<string, int>>, clock_mode: string, calendar_id?: ?string, warning_pct?: int, pause_conditions?: array<string, bool>, retarget_mode?: string, breach_escalation?: ?array{path: string, reason: string}}  $definition
     */
    public function create(array $definition, string $actorId): string
    {
        $this->assertDefinition($definition);
        $policyId = (string) Str::uuid7();
        $max = DB::table('sla_policies')->max('position');
        $position = (is_numeric($max) ? (int) $max : 0) + 1;

        DB::transaction(function () use ($policyId, $definition, $position, $actorId): void {
            DB::table('sla_policies')->insert([
                'id' => $policyId, 'tenant_id' => $this->context->tenantId(),
                'name' => $definition['name'], 'position' => $position,
                'match_conditions' => json_encode($definition['match_conditions'] ?? [], JSON_THROW_ON_ERROR),
                'targets' => json_encode($definition['targets'], JSON_THROW_ON_ERROR),
                'clock_mode' => $definition['clock_mode'],
                'calendar_id' => $definition['calendar_id'] ?? null,
                'warning_pct' => $definition['warning_pct'] ?? 80,
                'pause_conditions' => json_encode($definition['pause_conditions'] ?? ['pending_customer' => true, 'pending_internal' => false, 'channel_unreachable' => true], JSON_THROW_ON_ERROR),
                'retarget_mode' => $definition['retarget_mode'] ?? 'keep',
                'breach_escalation' => isset($definition['breach_escalation']) ? json_encode($definition['breach_escalation'], JSON_THROW_ON_ERROR) : null,
            ]);
            $this->announce($policyId, 'created', $actorId);
        });

        return $policyId;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $policyId, array $changes, string $actorId): void
    {
        if (isset($changes['match_conditions'])) {
            $this->assertMatchConditions((array) $changes['match_conditions']);
        }
        $update = array_intersect_key($changes, array_flip(['name', 'position', 'clock_mode', 'calendar_id', 'warning_pct', 'retarget_mode', 'active']));
        foreach (['match_conditions', 'targets', 'pause_conditions', 'breach_escalation'] as $jsonKey) {
            if (array_key_exists($jsonKey, $changes)) {
                $update[$jsonKey] = $changes[$jsonKey] === null ? null : json_encode($changes[$jsonKey], JSON_THROW_ON_ERROR);
            }
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($policyId, $update, $actorId): void {
            if (DB::table('sla_policies')->where('id', $policyId)->update($update) === 0) {
                throw new InvalidArgumentException('unknown policy');
            }
            $this->announce($policyId, 'updated', $actorId);
        });
    }

    /** @param array<string, mixed> $definition */
    private function assertDefinition(array $definition): void
    {
        $this->assertMatchConditions(is_array($definition['match_conditions'] ?? null) ? $definition['match_conditions'] : []);
        $targets = is_array($definition['targets'] ?? null) ? $definition['targets'] : [];
        if ($targets === []) {
            throw new InvalidArgumentException('a policy needs per-priority targets (FR-08)');
        }
        foreach ($targets as $priority => $stages) {
            if (! is_array($stages)) {
                throw new InvalidArgumentException('targets are per-priority stage maps (FR-08)');
            }
            foreach ($stages as $stage => $seconds) {
                if (! in_array($stage, self::STAGES, true) || ! is_int($seconds) || $seconds <= 0) {
                    throw new InvalidArgumentException(sprintf('unknown or non-positive stage target "%s" (FR-08)', (string) $stage));
                }
            }
        }
    }

    /** @param array<int|string, mixed> $conditions */
    private function assertMatchConditions(array $conditions): void
    {
        foreach (array_keys($conditions) as $key) {
            if (! in_array($key, self::MATCH_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('"%s" is not in FR-08\'s match vocabulary (department_id, priority, channel, tags, vip_source)', (string) $key));
            }
        }
    }

    private function announce(string $policyId, string $kind, string $actorId): void
    {
        $this->events->publish('sla.policy.changed', ['tenant_id' => $this->context->tenantId(), 'policy_id' => $policyId, 'kind' => $kind]);
        $this->audit->record('sla.policy.changed', 'sla_policy', $policyId, AuditActor::Staff, ['policy_id' => $policyId, 'kind' => $kind], actorId: $actorId);
    }
}
