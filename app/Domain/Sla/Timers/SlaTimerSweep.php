<?php

declare(strict_types=1);

namespace App\Domain\Sla\Timers;

use App\Domain\Sla\Clock\IntervalSet;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 12 FR-10 / NFR-02 / FS-02 — the 1-minute sweep over due SLA candidates.
 *
 * The T-03 division of authority: due CANDIDATES from the ZSET, every fire
 * RE-VERIFIED against row truth + the clock inside the tenant's own context
 * (a stale member drops harmlessly). Warn fires flip `warned` (the trigger
 * stamps the detection instant); the EVENT carries the COMPUTED due_at (RIDER
 * 1's honest-backdating reading) + late_fired. Breach likewise; the FR-08
 * breach-escalation hook LOGS its intent until P-DOORS clause 4 lands a
 * lawful system escalation door (unbuilt, stated — never a bypass).
 */
final class SlaTimerSweep
{
    public function __construct(
        private readonly SlaTimers $timers,
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
    ) {}

    /** @return array{warned: int, breached: int, dropped: int} */
    public function sweep(int $limit = 200): array
    {
        /** @var object{now_us: string} $row */
        $row = DB::connection(PlatformConnection::NAME)->selectOne('SELECT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint AS now_us');
        $nowUs = (int) $row->now_us;
        $result = ['warned' => 0, 'breached' => 0, 'dropped' => 0];

        foreach ($this->timers->due($nowUs, $limit) as $stageId) {
            /** @var object{tenant_id: string}|null $owner */
            $owner = PlatformConnection::run(
                PlatformActor::RetentionJob,
                'Doc 12 FR-10 sweep: resolve a due stage candidate to its tenant',
                static function () use ($stageId): ?object {
                    /** @var object{tenant_id: string}|null $row */
                    $row = DB::connection(PlatformConnection::NAME)
                        ->selectOne('SELECT tenant_id FROM sla_instance_stages WHERE id = ?', [$stageId]);

                    return $row;
                },
            );
            if ($owner === null) {
                $this->timers->drop($stageId); // a stale candidate (row gone) drops harmlessly
                $result['dropped']++;

                continue;
            }
            $fired = $this->context->runFor($owner->tenant_id, ResolutionSource::Console, function () use ($owner, $stageId, $nowUs): array {
                app(RlsSession::class)->set($owner->tenant_id);

                return $this->fireOne($stageId, $nowUs);
            });
            $result['warned'] += $fired['warned'];
            $result['breached'] += $fired['breached'];
            $result['dropped'] += $fired['dropped'];
        }

        return $result;
    }

    /** FS-02: restore candidates from row truth after infrastructure loss (late-not-early). */
    public function rebuild(): int
    {
        /** @var list<object{id: string, tenant_id: string}> $rows */
        $rows = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 12 FS-02: rebuild SLA timer candidates from row truth',
            static fn (): array => DB::connection(PlatformConnection::NAME)
                ->select('SELECT id, tenant_id FROM sla_instance_stages WHERE cleared_at IS NULL'),
        );
        $restored = 0;
        foreach ($rows as $row) {
            $restored += $this->context->runFor($row->tenant_id, ResolutionSource::Console, function () use ($row): int {
                app(RlsSession::class)->set($row->tenant_id);
                /** @var object{id: string, instance_id: string, clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string, warned: bool, breached: bool, cleared_at: ?string}|null $stage */
                $stage = DB::table('sla_instance_stages')->where('id', $row->id)->first();
                if ($stage === null) {
                    return 0;
                }
                [$snapshot, $calendar] = $this->snapshotOf($stage->instance_id);
                $this->timers->syncStage($stage, $snapshot, $calendar);

                return 1;
            });
        }

        return $restored;
    }

    /** @return array{warned: int, breached: int, dropped: int} */
    private function fireOne(string $stageId, int $nowUs): array
    {
        /** @var object{id: string, instance_id: string, stage: string, clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string, warned: bool, breached: bool, cleared_at: ?string, late_fired: bool}|null $stage */
        $stage = DB::table('sla_instance_stages')->where('id', $stageId)->first();
        if ($stage === null || $stage->cleared_at !== null) {
            $this->timers->drop($stageId);

            return ['warned' => 0, 'breached' => 0, 'dropped' => 1];
        }
        [$snapshot, $calendar] = $this->snapshotOf($stage->instance_id);
        $math = $this->timers->stageMath($stage, $calendar, $snapshot['warning_pct'] ?? 80);
        $fired = ['warned' => 0, 'breached' => 0, 'dropped' => 0];

        if (! (bool) $stage->warned && $math['warn_at_us'] !== null && $math['warn_at_us'] <= $nowUs) {
            DB::table('sla_instance_stages')->where('id', $stageId)->update([
                'warned' => true,
                'late_fired' => (bool) $stage->late_fired || $this->timers->isLate($nowUs, $math['warn_at_us']),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->publishFire('sla.warned', $stage, $math['warn_at_us'], $nowUs);
            $fired['warned'] = 1;
        }
        /** @var object{id: string, instance_id: string, stage: string, clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string, warned: bool, breached: bool, cleared_at: ?string, late_fired: bool}|null $stage */
        $stage = DB::table('sla_instance_stages')->where('id', $stageId)->first();
        if ($stage !== null && ! (bool) $stage->breached && $math['breach_at_us'] !== null && $math['breach_at_us'] <= $nowUs) {
            DB::table('sla_instance_stages')->where('id', $stageId)->update([
                'breached' => true,
                'late_fired' => (bool) $stage->late_fired || $this->timers->isLate($nowUs, $math['breach_at_us']),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->publishFire('sla.breached', $stage, $math['breach_at_us'], $nowUs);
            $escalation = $snapshot['breach_escalation'] ?? null;
            if (is_array($escalation)) {
                // FR-08's hook: a LAWFUL system escalation door does not exist until
                // P-DOORS clause 4 lands — the intent is logged, never bypassed.
                Log::warning('sla breach-escalation hook pending P-DOORS (unbuilt door — logged, not bypassed)', [
                    'stage_id' => $stageId, 'path' => $escalation['path'],
                ]);
            }
            $fired['breached'] = 1;
        }
        /** @var object{id: string, instance_id: string, stage: string, clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string, warned: bool, breached: bool, cleared_at: ?string, late_fired: bool}|null $stage */
        $stage = DB::table('sla_instance_stages')->where('id', $stageId)->first();
        if ($stage !== null) {
            $this->timers->syncStage($stage, $snapshot, $calendar);
        }

        return $fired;
    }

    /** @param object{instance_id: string, stage: string} $stage */
    private function publishFire(string $event, object $stage, int $dueUs, int $nowUs): void
    {
        $ticketId = DB::table('sla_instances')->where('id', $stage->instance_id)->value('ticket_id');
        DB::transaction(fn (): string => $this->events->publish($event, [
            'tenant_id' => $this->context->tenantId(),
            'ticket_id' => is_string($ticketId) ? $ticketId : '',
            'instance_id' => $stage->instance_id,
            'stage' => $stage->stage,
            'due_at' => IntervalSet::fromUs($dueUs)->format('Y-m-d\TH:i:s.u\Z'),
            'late_fired' => $this->timers->isLate($nowUs, $dueUs),
        ]));
    }

    /** @return array{0: array{warning_pct?: int, breach_escalation?: ?array{path: string, reason: string}}, 1: array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null} */
    private function snapshotOf(string $instanceId): array
    {
        /** @var object{policy_snapshot: string, calendar_snapshot: ?string}|null $instance */
        $instance = DB::table('sla_instances')->where('id', $instanceId)->first(['policy_snapshot', 'calendar_snapshot']);
        if ($instance === null) {
            return [[], null];
        }
        /** @var array{warning_pct?: int, breach_escalation?: ?array{path: string, reason: string}} $snapshot */
        $snapshot = json_decode($instance->policy_snapshot, true, 16) ?: [];
        /** @var array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null $calendar */
        $calendar = $instance->calendar_snapshot === null ? null : json_decode($instance->calendar_snapshot, true, 16);
        $calendar = is_array($calendar) ? $calendar : null;

        return [$snapshot, $calendar];
    }
}
