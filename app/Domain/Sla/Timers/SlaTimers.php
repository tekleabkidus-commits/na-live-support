<?php

declare(strict_types=1);

namespace App\Domain\Sla\Timers;

use App\Domain\Sla\Clock\CalendarSnapshot;
use App\Domain\Sla\Clock\IntervalSet;
use App\Domain\Sla\Clock\SlaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Doc 12 FR-10 / T-04 / NFR-02 / FS-01 / FS-02 — SLA timers on Doc 06's T-03
 * infrastructure, verbatim in shape:
 *
 *   ROW TRUTH + CANDIDATE ZSET. `sla_timers` on redis-durable holds
 *   member = stage id, score = the next UNFIRED instant in INTEGER
 *   MICROSECONDS. Nothing ever FIRES on the ZSET's say-so — the sweep
 *   re-verifies every candidate against the row + the clock library inside
 *   its own pass. A LOST ZSET delays fires until rebuild() restores candidates
 *   from row truth (FS-02: late-not-early, flagged late_fired); a STALE member
 *   drops harmlessly. Next-fire-at scheduling, never per-second ticking.
 *   FS-01: these timers are independent of the automation action queue —
 *   deadlines never slip because actions backlog.
 */
final class SlaTimers
{
    public const ZSET = 'sla_timers';

    private const CONNECTION = 'durable';

    /** Fired later than this after the computed instant → flagged late_fired (FS-02's honesty line). */
    private const LATE_GRACE_US = 60_000_000;

    public function __construct(private readonly SlaClock $clock) {}

    /**
     * The stage's clock answers, from row truth: elapsed now, the computed
     * warn/breach instants (µs), and whether an OPEN pause stops the clock.
     *
     * @param  object{clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string}  $row
     * @param  array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null  $calendar
     * @return array{elapsed_us: int, warn_at_us: ?int, breach_at_us: ?int, paused: bool, now_us: int, now_iso: string, effective_total_us: int, warn_total_us: int}
     */
    public function stageMath(object $row, ?array $calendar, ?int $warningPct = null): array
    {
        /** @var object{now: string} $nowRow */
        $nowRow = DB::selectOne('SELECT clock_timestamp() AS now');
        $now = new \DateTimeImmutable($nowRow->now);
        $nowUs = IntervalSet::us($now);
        $startUs = IntervalSet::us(new \DateTimeImmutable($row->clock_start_at));

        /** @var list<array{from: string, until: ?string, reason: string}> $pauseList */
        $pauseList = json_decode($row->pauses, true, 8) ?: [];
        $paused = false;
        $ranges = [];
        foreach ($pauseList as $pause) {
            $fromUs = IntervalSet::us(new \DateTimeImmutable($pause['from']));
            $untilUs = $pause['until'] === null ? null : IntervalSet::us(new \DateTimeImmutable($pause['until']));
            if ($untilUs === null) {
                $paused = true;
                $untilUs = $nowUs + 4 * 365 * 86_400 * 1_000_000; // open pause covers the horizon
            }
            $ranges[] = [$fromUs, $untilUs];
        }
        $pauses = new IntervalSet($ranges);
        $snapshot = $calendar === null ? null : CalendarSnapshot::fromArray($calendar);

        $effective = is_string($row->retargeted)
            ? (static function (string $retargeted): int {
                /** @var array{elapsed_at_us: int, remaining_after_us: int} $r */
                $r = json_decode($retargeted, true, 4);

                return $r['elapsed_at_us'] + $r['remaining_after_us'];
            })($row->retargeted)
            : (int) $row->target_us;
        $warningPct ??= 80;
        $warnTotal = $this->clock->warningThresholdUs($effective, $warningPct);

        // A clock whose start sits ahead of now has simply not started: zero
        // elapsed, the fire instants walked forward from the start (C-11 truth
        // can outrun a test's or an event's stamped instant by microseconds).
        $elapsed = $this->clock->elapsedMicroseconds(new IntervalSet([[$startUs, max($startUs, $nowUs)]]), $snapshot, $pauses);
        $warnAt = $paused ? null : $this->clock->instantWhenElapsedReaches($warnTotal, $startUs, $snapshot, $pauses);
        $breachAt = $paused ? null : $this->clock->instantWhenElapsedReaches($effective, $startUs, $snapshot, $pauses);

        return [
            'elapsed_us' => $elapsed, 'warn_at_us' => $warnAt, 'breach_at_us' => $breachAt,
            'paused' => $paused, 'now_us' => $nowUs, 'now_iso' => $nowRow->now,
            'effective_total_us' => $effective, 'warn_total_us' => $warnTotal,
        ];
    }

    /**
     * Re-arm the stage's candidate: score = the next UNFIRED instant; a paused
     * or fully-fired stage carries no candidate.
     *
     * @param  object{id: string, clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string, warned: bool, breached: bool, cleared_at: ?string}  $row
     * @param  array{warning_pct?: int}  $snapshot
     * @param  array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null  $calendar
     */
    public function syncStage(object $row, array $snapshot, ?array $calendar): void
    {
        $math = $this->stageMath($row, $calendar, $snapshot['warning_pct'] ?? 80);
        $next = null;
        if (! (bool) $row->warned && $math['warn_at_us'] !== null) {
            $next = $math['warn_at_us'];
        } elseif (! (bool) $row->breached && $math['breach_at_us'] !== null) {
            $next = $math['breach_at_us'];
        }
        $connection = Redis::connection(self::CONNECTION);
        if ($next === null || $row->cleared_at !== null) {
            $connection->zrem(self::ZSET, $row->id);
            DB::table('sla_instance_stages')->where('id', $row->id)
                ->update(['target_at' => null, 'updated_at' => DB::raw('clock_timestamp()')]);

            return;
        }
        $connection->zadd(self::ZSET, [$row->id => $next]);
        DB::table('sla_instance_stages')->where('id', $row->id)->update([
            'target_at' => IntervalSet::fromUs($math['breach_at_us'] ?? $next)->format('Y-m-d H:i:s.uP'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    /** Whether a fire at $nowUs against a computed $dueUs is honestly LATE (FS-02). */
    public function isLate(int $nowUs, int $dueUs): bool
    {
        return $nowUs - $dueUs > self::LATE_GRACE_US;
    }

    /** @return list<string> due stage ids (candidates only — the sweep re-verifies each) */
    public function due(int $nowUs, int $limit = 200): array
    {
        /** @var list<string> $members */
        $members = Redis::connection(self::CONNECTION)
            ->zrangebyscore(self::ZSET, '-inf', (string) $nowUs, ['limit' => ['offset' => 0, 'count' => $limit]]);

        return $members;
    }

    public function drop(string $stageId): void
    {
        Redis::connection(self::CONNECTION)->zrem(self::ZSET, $stageId);
    }
}
