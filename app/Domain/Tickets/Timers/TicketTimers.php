<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Timers;

use App\Models\Ticket;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * T-03's timer store: Redis ZSET `ticket_timers` + COLUMN TRUTH (Doc 06 FR-07, FS-01).
 *
 * THE DIVISION OF AUTHORITY IS THE WHOLE DESIGN. The `tickets.auto_close_at` column is
 * the truth; the ZSET is a candidate list the 1-minute sweep reads so "what is due?"
 * is one O(log n) Redis call instead of a table scan. Nothing is ever CLOSED on the
 * ZSET's say-so — the sweep re-verifies every candidate against the columns, inside
 * its own transaction (TicketTimerSweep). That one rule yields FS-01's asymmetry for
 * free: a LOST ZSET delays auto-closes until `rebuild()` restores the candidates from
 * the columns; a STALE ZSET member is dropped harmlessly at its DB re-check. Delayed,
 * never premature — in physics, not in care.
 *
 * ZSET WRITES ARE DELIBERATELY NOT afterCommit. A member added for a transaction that
 * rolls back is a stale candidate the sweep drops; an afterCommit member LOST to a
 * crash between commit and push would be an armed timer no sweep ever sees — silent
 * until rebuild. Between a harmless extra and a silent missing, the extra wins (the
 * same duplicate-over-loss choice the outbox relay documents).
 *
 * SCORES ARE INTEGER MICROSECONDS since the epoch — the R-23 family principle. A
 * 1-minute sweep does not need that resolution today; the principle exists so no
 * future consumer of these scores inherits a second-grain coin flip.
 *
 * ON REDIS-DURABLE: T-03's timers are named durable-instance state in the stack table
 * (Doc 01 — "queues, outbox, timers"), because `allkeys-lru` on the volatile instance
 * could EVICT an armed timer under memory pressure, which is FS-01's failure arriving
 * as configuration.
 */
final class TicketTimers
{
    public const ZSET = 'ticket_timers';

    private const CONNECTION = 'durable';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Arm the FR-07 auto-close timer for a ticket that just entered `resolved`.
     *
     * Runs inside the transition's own transaction. The deadline is DB arithmetic on
     * the trigger-stamped `resolved_at` — no app clock touches it (C-11) — and it is
     * MATERIALIZED, which is SS-03: the tenant's `tickets.autoclose.hours` is read
     * once, now, and a later settings change cannot re-target an armed timer.
     *
     * The guard (`status = 'resolved' AND resolved_at IS NOT NULL`) makes a misplaced
     * call write nothing rather than arm a timer on a ticket that is not resolved.
     *
     * @return bool whether a timer was armed
     */
    public function armAutoClose(Ticket $ticket): bool
    {
        $hours = $this->settings->get('tickets.autoclose.hours');
        assert(is_int($hours));

        $armed = DB::update(
            'UPDATE tickets
                SET auto_close_at = resolved_at + make_interval(hours => ?),
                    updated_at = clock_timestamp()
              WHERE id = ? AND status = ? AND resolved_at IS NOT NULL',
            [$hours, $ticket->identifier(), 'resolved'],
        );

        if ($armed !== 1) {
            return false;
        }

        $ticket->refresh();
        $deadline = $ticket->auto_close_at;

        if ($deadline !== null) {
            $this->add($this->member($ticket), (int) $deadline->format('Uu'));
        }

        return true;
    }

    /**
     * Cancel the auto-close timer (FR-07: "timer cancels if the customer replies";
     * also fired when the timer is CONSUMED by the close itself, and when any other
     * exit from `resolved` makes the deadline meaningless).
     */
    public function cancelAutoClose(Ticket $ticket): void
    {
        DB::update(
            'UPDATE tickets SET auto_close_at = NULL, updated_at = clock_timestamp() WHERE id = ? AND auto_close_at IS NOT NULL',
            [$ticket->identifier()],
        );

        $this->remove($this->member($ticket));
    }

    /**
     * Due candidates: members whose score is at or before now. CANDIDATES ONLY —
     * the sweep's DB re-check is the authority on every one of them.
     *
     * @return list<string> members, "{tenant_id}|{ticket_id}"
     */
    public function due(int $limit = 500): array
    {
        /** @var list<string> $members */
        $members = Redis::connection(self::CONNECTION)->zrangebyscore(
            self::ZSET,
            '-inf',
            (string) $this->nowMicroseconds(),
            ['limit' => ['offset' => 0, 'count' => $limit]],
        );

        return $members;
    }

    public function add(string $member, int $scoreMicroseconds): void
    {
        Redis::connection(self::CONNECTION)->zadd(self::ZSET, [$member => $scoreMicroseconds]);
    }

    public function remove(string $member): void
    {
        Redis::connection(self::CONNECTION)->zrem(self::ZSET, $member);
    }

    public function member(Ticket $ticket): string
    {
        return $ticket->tenant_id.'|'.$ticket->identifier();
    }

    /** @return array{0: string, 1: string} [tenant_id, ticket_id] */
    public function parseMember(string $member): array
    {
        [$tenantId, $ticketId] = explode('|', $member, 2);

        return [$tenantId, $ticketId];
    }

    /**
     * FS-01: "Timer ZSET lost → rebuild from columns (truth)."
     *
     * Platform-scope, because the armed set spans every tenant and the truth column
     * carries its own tenant id. Rebuild only ever ADDS candidates at their exact
     * column deadline — it can restore a delayed timer, it cannot invent a premature
     * one, because closing still happens only after the sweep's DB re-check.
     *
     * @return int timers restored to the candidate list
     */
    public function rebuild(): int
    {
        /** @var list<object{tenant_id: string, id: string, deadline_us: string}> $rows */
        $rows = PlatformConnection::run(
            PlatformActor::ReconciliationJob,
            'ticket timer rebuild',
            fn (): array => DB::connection(PlatformConnection::NAME)->select(
                "SELECT tenant_id, id, (EXTRACT(EPOCH FROM auto_close_at) * 1000000)::bigint AS deadline_us
                   FROM tickets
                  WHERE auto_close_at IS NOT NULL AND status = 'resolved'",
            ),
        );

        foreach ($rows as $row) {
            $this->add($row->tenant_id.'|'.$row->id, (int) $row->deadline_us);
        }

        return count($rows);
    }

    /**
     * The candidate cut-off. An APP clock, permissibly: this bounds which candidates
     * the sweep LOOKS AT this minute — the ordering/closing decision itself is the
     * sweep's DB-time re-check (C-11 governs decisions, not short-lists). A skewed
     * clock here delays or hastens a LOOK by its skew; it cannot close anything early.
     */
    private function nowMicroseconds(): int
    {
        return (int) (microtime(true) * 1_000_000);
    }
}
