<?php

declare(strict_types=1);

namespace App\Domain\Sla\Instances;

use App\Domain\Sla\Clock\SlaClock;
use App\Domain\Sla\Timers\SlaTimers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 12 FR-09 / FR-10 / EC-02 / EC-03 / EC-04 / T-04 — stage arm/clear/pause/
 * retarget, and the recompute that keeps next-fire-at honest.
 *
 * - EC-02: next_response is IDEMPOTENT WHILE ARMED — the live partial unique
 *   holds the FIRST unanswered message's clock; five rapid messages change
 *   nothing.
 * - Pauses are RECORDED INTERVALS ({from, until|null, reason}) on the stage
 *   row (SS-02's checkable arithmetic); the clock library unions them (EC-04
 *   can never double-subtract). An OPEN pause stops the clock: no next-fire
 *   is armed until it closes.
 * - EC-03: retarget_remaining stores the documented formula's WHOLE record
 *   ({old_target_us, new_target_us, remaining_before_us, remaining_after_us,
 *   elapsed_at_us, at}) in `retargeted`; the effective total derives from it
 *   (target_us itself is immutable by grant — nominal history never rewrites).
 * - The unreachable pause rides the telegram window events (Doc 06 BR-07's
 *   column stays Doc 05's; the EVENTS drive the recorded interval).
 */
final class StageLifecycle
{
    public function __construct(
        private readonly SlaClock $clock,
        private readonly SlaTimers $timers,
    ) {}

    /** @param array<string, array<string, int>> $targets */
    public function armStage(string $instanceId, string $stage, string $priority, array $targets, string $clockStart): void
    {
        $seconds = $targets[$priority][$stage.'_s'] ?? $targets['normal'][$stage.'_s'] ?? null;
        if (! is_int($seconds) || $seconds <= 0) {
            return; // no target for this stage at this priority — nothing arms, honestly
        }
        $tenantId = DB::table('sla_instances')->where('id', $instanceId)->value('tenant_id');
        // EC-02: the live partial unique makes re-arm a no-op — the FIRST clock stands.
        DB::table('sla_instance_stages')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'instance_id' => $instanceId, 'stage' => $stage,
            'target_us' => $seconds * 1_000_000, 'clock_start_at' => $clockStart,
        ]);
    }

    public function onCustomerMessage(string $ticketId, string $occurredAt): void
    {
        $context = $this->instanceOf($ticketId);
        if ($context === null) {
            return;
        }
        [$instance, $snapshot] = $context;
        $priorityRaw = DB::table('tickets')->where('id', $ticketId)->value('priority');
        $this->armStage((string) $instance->id, 'next_response', is_string($priorityRaw) ? $priorityRaw : 'normal', $snapshot['targets'], $occurredAt);
        $this->recomputeTicket($ticketId);
    }

    /** A staff/AI PUBLIC reply clears first_response and next_response (FR-09). */
    public function onStaffReply(string $ticketId): void
    {
        $context = $this->instanceOf($ticketId);
        if ($context === null) {
            return;
        }
        [$instance] = $context;
        DB::table('sla_instance_stages')
            ->where('instance_id', $instance->id)->whereNull('cleared_at')
            ->whereIn('stage', ['first_response', 'next_response'])
            ->update(['cleared_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
        $this->recomputeTicket($ticketId);
    }

    public function onStatusChanged(string $ticketId, string $old, string $new, string $occurredAt): void
    {
        $context = $this->instanceOf($ticketId);
        if ($context === null) {
            return;
        }
        [$instance, $snapshot] = $context;
        $toggles = $snapshot['pause_conditions'];

        foreach (['pending_customer', 'pending_internal'] as $reason) {
            if (($toggles[$reason] ?? false) === true) {
                if ($new === $reason) {
                    $this->openPause((string) $instance->id, $reason, $occurredAt);
                } elseif ($old === $reason) {
                    $this->closePause((string) $instance->id, $reason, $occurredAt);
                }
            }
        }
        if ($new === 'resolved') {
            DB::table('sla_instance_stages')
                ->where('instance_id', $instance->id)->whereNull('cleared_at')->where('stage', 'resolution')
                ->update(['cleared_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
        }
        if (in_array($old, ['resolved', 'closed'], true) && in_array($new, ['open', 'new'], true)) {
            // FR-09's lifecycle read for a reopen: the resolution obligation returns,
            // its clock from the reopen instant (a fresh live row; history stands).
            $priorityRaw = DB::table('tickets')->where('id', $ticketId)->value('priority');
            $this->armStage((string) $instance->id, 'resolution', is_string($priorityRaw) ? $priorityRaw : 'normal', $snapshot['targets'], $occurredAt);
        }
        $this->recomputeTicket($ticketId);
    }

    /** EC-03: retarget per the SNAPSHOT's mode; `keep` records nothing and changes nothing. */
    public function onPriorityChanged(string $ticketId, string $newPriority): void
    {
        $context = $this->instanceOf($ticketId);
        if ($context === null) {
            return;
        }
        [$instance, $snapshot, $calendar] = $context;
        if ($snapshot['retarget_mode'] !== 'retarget_remaining') {
            $this->recomputeTicket($ticketId);

            return;
        }
        $rows = DB::table('sla_instance_stages')
            ->where('instance_id', $instance->id)->whereNull('cleared_at')->where('breached', false)->get();
        /** @var object{id: string, stage: string, pauses: string, retargeted: ?string, target_us: int|string, clock_start_at: string, warned: bool, breached: bool, cleared_at: ?string} $row */
        foreach ($rows as $row) {
            $newSeconds = $snapshot['targets'][$newPriority][$row->stage.'_s'] ?? null;
            if (! is_int($newSeconds) || $newSeconds <= 0) {
                continue; // the new priority draws no target for this stage — keep (stated by absence)
            }
            $math = $this->timers->stageMath($row, $calendar);
            $effective = $this->effectiveTotal($row);
            $remainingBefore = max(0, $effective - $math['elapsed_us']);
            $remainingAfter = $this->clock->retargetRemaining($remainingBefore, $effective, $newSeconds * 1_000_000);
            DB::table('sla_instance_stages')->where('id', $row->id)->update([
                'retargeted' => json_encode([
                    'old_target_us' => $effective,
                    'new_target_us' => $newSeconds * 1_000_000,
                    'remaining_before_us' => $remainingBefore,
                    'remaining_after_us' => $remainingAfter,
                    'elapsed_at_us' => $math['elapsed_us'],
                    'at' => $math['now_iso'],
                ], JSON_THROW_ON_ERROR),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
        }
        $this->recomputeTicket($ticketId);
    }

    public function onWindowEvent(string $conversationId, bool $unreachable, string $occurredAt): void
    {
        $ticketId = DB::table('tickets')->where('conversation_id', $conversationId)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->orderByDesc('created_at')->value('id');
        if (! is_string($ticketId)) {
            return;
        }
        $context = $this->instanceOf($ticketId);
        if ($context === null) {
            return;
        }
        [$instance, $snapshot] = $context;
        if (($snapshot['pause_conditions']['channel_unreachable'] ?? false) !== true) {
            return; // EC-10's orthogonality: the flag changes answers only where the policy says so
        }
        $unreachable
            ? $this->openPause((string) $instance->id, 'channel_unreachable', $occurredAt)
            : $this->closePause((string) $instance->id, 'channel_unreachable', $occurredAt);
        $this->recomputeTicket($ticketId);
    }

    /** Recompute every live stage's next-fire and re-arm the ZSET (T-04's no-ticking law). */
    public function recomputeTicket(string $ticketId): void
    {
        $context = $this->instanceOf($ticketId);
        if ($context === null) {
            return;
        }
        [$instance, $snapshot, $calendar] = $context;
        /** @var list<object{id: string, clock_start_at: string, pauses: string, retargeted: ?string, target_us: int|string, warned: bool, breached: bool, cleared_at: ?string}> $rows */
        $rows = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->whereNull('cleared_at')->get()->all();
        foreach ($rows as $row) {
            $this->timers->syncStage($row, $snapshot, $calendar);
        }
    }

    /** The EC-03-aware total: elapsed-at-retarget + remaining-after, else the nominal target. */
    /** @param object{retargeted: ?string, target_us: int|string} $row */
    public function effectiveTotal(object $row): int
    {
        if (is_string($row->retargeted)) {
            /** @var array{elapsed_at_us: int, remaining_after_us: int} $r */
            $r = json_decode($row->retargeted, true, 4);

            return $r['elapsed_at_us'] + $r['remaining_after_us'];
        }

        return (int) $row->target_us;
    }

    private function openPause(string $instanceId, string $reason, string $from): void
    {
        /** @var object{id: string, pauses: string} $row */
        foreach (DB::table('sla_instance_stages')->where('instance_id', $instanceId)->whereNull('cleared_at')->get() as $row) {
            /** @var list<array{from: string, until: ?string, reason: string}> $pauses */
            $pauses = json_decode($row->pauses, true, 8) ?: [];
            foreach ($pauses as $pause) {
                if ($pause['reason'] === $reason && $pause['until'] === null) {
                    continue 2; // already open — idempotent under redelivery (C-09)
                }
            }
            $pauses[] = ['from' => $from, 'until' => null, 'reason' => $reason];
            DB::table('sla_instance_stages')->where('id', $row->id)
                ->update(['pauses' => json_encode($pauses, JSON_THROW_ON_ERROR), 'updated_at' => DB::raw('clock_timestamp()')]);
        }
    }

    private function closePause(string $instanceId, string $reason, string $until): void
    {
        /** @var object{id: string, pauses: string} $row */
        foreach (DB::table('sla_instance_stages')->where('instance_id', $instanceId)->whereNull('cleared_at')->get() as $row) {
            /** @var list<array{from: string, until: ?string, reason: string}> $pauses */
            $pauses = json_decode($row->pauses, true, 8) ?: [];
            $changed = false;
            foreach ($pauses as $i => $pause) {
                if ($pause['reason'] === $reason && $pause['until'] === null) {
                    $pauses[$i]['until'] = $until;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('sla_instance_stages')->where('id', $row->id)
                    ->update(['pauses' => json_encode($pauses, JSON_THROW_ON_ERROR), 'updated_at' => DB::raw('clock_timestamp()')]);
            }
        }
    }

    /** @return array{0: object{id: string, policy_snapshot: string, calendar_snapshot: ?string}, 1: array{targets: array<string, array<string, int>>, warning_pct: int, pause_conditions: array<string, bool>, retarget_mode: string, breach_escalation: ?array{path: string, reason: string}}, 2: array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null}|null */
    private function instanceOf(string $ticketId): ?array
    {
        /** @var object{id: string, policy_snapshot: string, calendar_snapshot: ?string}|null $instance */
        $instance = DB::table('sla_instances')->where('ticket_id', $ticketId)->first();
        if ($instance === null) {
            return null;
        }
        /** @var array{targets: array<string, array<string, int>>, warning_pct: int, pause_conditions: array<string, bool>, retarget_mode: string, breach_escalation: ?array{path: string, reason: string}} $snapshot */
        $snapshot = json_decode($instance->policy_snapshot, true, 16, JSON_THROW_ON_ERROR);
        /** @var array{timezone: string, weekdays: array<int|string, list<array{open: string, close: string}>>, holidays: list<array{date: string, recurring: bool}>}|null $calendar */
        $calendar = $instance->calendar_snapshot === null ? null : json_decode($instance->calendar_snapshot, true, 16, JSON_THROW_ON_ERROR);
        $calendar = is_array($calendar) ? $calendar : null;

        return [$instance, $snapshot, $calendar];
    }
}
