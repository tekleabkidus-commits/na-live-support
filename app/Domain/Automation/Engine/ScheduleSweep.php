<?php

declare(strict_types=1);

namespace App\Domain\Automation\Engine;

use App\Domain\Routing\Hours\HoursContract;
use App\Jobs\Automation\ActionChainJob;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * Doc 12 FR-01's `schedule` trigger (EC-10) — the 1-minute sweep, the house
 * sweep shape (cross-tenant discovery on the platform connection, each rule
 * processed INSIDE its tenant's context — the RbacExpireOverrides pattern).
 *
 * A due rule evaluates its conditions against the TIME FACTS ONLY (no event,
 * no ticket — the scheduled shape §19 grows from) and dispatches its chain;
 * the execution row's event_id is a minted uuid (unique per firing, C-09's
 * anchor for a re-entrant sweep). next_run derives from the cron in the
 * TENANT's timezone (EC-10: a timezone change clears the stamp — step 3 —
 * and this sweep re-derives it here). Fires LATE-NOT-EARLY: a missed window
 * fires once on recovery, never retroactively per missed slot. The R-04
 * pause switch silences scheduled rules exactly like evented ones.
 */
final class ScheduleSweep
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ConditionTree $conditions,
    ) {}

    /** @return array{fired: int, rearmed: int} */
    public function sweep(): array
    {
        /** @var list<object{id: string, tenant_id: string}> $due */
        $due = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 12 FR-01 schedule sweep: find due or unarmed schedule rules',
            static fn (): array => DB::connection(PlatformConnection::NAME)->select(
                "SELECT id, tenant_id FROM automation_rules
                 WHERE trigger = 'schedule' AND active
                   AND (schedule_next_run_at IS NULL OR schedule_next_run_at <= now())
                 ORDER BY tenant_id, position, id",
            ),
        );
        $fired = 0;
        $rearmed = 0;
        foreach ($due as $row) {
            [$didFire, $didRearm] = $this->context->runFor($row->tenant_id, ResolutionSource::Console, function () use ($row): array {
                app(RlsSession::class)->set($row->tenant_id);

                return $this->processRule($row->id);
            });
            $fired += $didFire;
            $rearmed += $didRearm;
        }

        return ['fired' => $fired, 'rearmed' => $rearmed];
    }

    /** @return array{0: int, 1: int} [fired, rearmed] */
    private function processRule(string $ruleId): array
    {
        /** @var object{id: string, active_revision_id: ?string, schedule_cron: ?string, schedule_next_run_at: ?string, cooldown_seconds: int}|null $rule */
        $rule = DB::table('automation_rules')->where('id', $ruleId)->first();
        if ($rule === null || ! is_string($rule->schedule_cron)) {
            return [0, 0];
        }
        $hours = app(HoursContract::class);
        $tz = $hours->timezone();
        $now = $hours->now();

        // Arm-only pass: an unarmed rule (fresh, or EC-10-cleared) gets its next
        // instant and does NOT fire — activation is never itself a firing.
        $next = (new CronExpression($rule->schedule_cron))
            ->getNextRunDate($now->setTimezone($tz), 0, false, $tz->getName());
        $nextUtc = \DateTimeImmutable::createFromInterface($next)->setTimezone(new \DateTimeZone('UTC'));
        if ($rule->schedule_next_run_at === null) {
            DB::table('automation_rules')->where('id', $ruleId)
                ->update(['schedule_next_run_at' => $nextUtc->format('Y-m-d H:i:sP'), 'updated_at' => DB::raw('clock_timestamp()')]);

            return [0, 1];
        }

        // Due: re-arm FIRST (late-not-early; one firing per recovery), then fire.
        DB::table('automation_rules')->where('id', $ruleId)
            ->update(['schedule_next_run_at' => $nextUtc->format('Y-m-d H:i:sP'), 'updated_at' => DB::raw('clock_timestamp()')]);

        if (Feature::active('automation.rules_pause')) {
            return [0, 1]; // R-04: re-armed, silenced.
        }
        if ($rule->active_revision_id === null) {
            return [0, 1];
        }
        $definition = DB::table('automation_rule_revisions')->where('id', $rule->active_revision_id)->value('definition');
        if (! is_string($definition)) {
            return [0, 1];
        }
        /** @var array{conditions?: array<string, mixed>, actions?: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($definition, true, 32, JSON_THROW_ON_ERROR);

        $facts = [
            'time.within_business_hours' => $hours->isOpen(null, $now),
            'time.day_of_week' => (int) $now->setTimezone($tz)->format('N'),
        ];
        $result = $this->conditions->evaluate($decoded['conditions'] ?? ['all' => []], $facts);

        $executionId = (string) Str::uuid7();
        DB::table('automation_rule_executions')->insert([
            'id' => $executionId,
            'tenant_id' => $this->context->tenantId(),
            'rule_id' => $ruleId,
            'revision_id' => $rule->active_revision_id,
            'event_id' => (string) Str::uuid7(), // one firing, one anchor — no outbox event exists for a schedule tick
            'context_ref' => '{}',
            'condition_results' => json_encode($result['trace'], JSON_THROW_ON_ERROR),
            'action_outcomes' => '[]',
            'guard_trips' => '[]',
            'matched' => $result['matched'],
        ]);
        if (! $result['matched'] || ($decoded['actions'] ?? []) === []) {
            return [0, 1];
        }
        ActionChainJob::dispatch($executionId, array_slice($decoded['actions'], 0, Guards::MAX_ACTIONS_PER_EVENT), 1, $facts)->onQueue('automation');

        return [1, 1];
    }
}
