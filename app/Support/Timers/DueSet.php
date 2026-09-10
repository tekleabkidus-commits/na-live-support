<?php

declare(strict_types=1);

namespace App\Support\Timers;

use Illuminate\Support\Facades\Redis;

/**
 * THE platform timer kernel (A-65, ruled at the S14 plan approval) — a pure
 * generalization of TicketTimers' documented laws (Doc 06 FR-07, FS-01):
 *
 *  - a redis-DURABLE ZSET per kind (`due:{kind}`), member `{tenant}|{id}`,
 *    score integer MICROSECONDS (R-23's family principle);
 *  - `due()` returns CANDIDATES ONLY — nothing is ever closed on the ZSET's
 *    say-so; every sweep re-verifies its candidate against the owning columns
 *    inside its own transaction;
 *  - a LOST ZSET delays, never fires early — `rebuild()` restores candidates
 *    from column truth at their exact column deadline;
 *  - a STALE member is dropped harmlessly at its DB re-check;
 *  - ZSET writes are deliberately NOT afterCommit (the TicketTimers reasoning:
 *    a member for a rolled-back row is harmless — its re-check finds nothing;
 *    a missing member for a committed row is a silent delay until rebuild).
 *
 * Doc 14 is the first consumer (`sms_hold_release`, `sms_expiry`,
 * `sms_queued_ttl`). The six existing per-module copies migrate in their
 * owners' windows (the A-65 reopener for Docs 18/19) — none is touched here.
 */
final class DueSet
{
    private const CONNECTION = 'durable';

    public function schedule(string $kind, string $member, int $scoreMicroseconds): void
    {
        Redis::connection(self::CONNECTION)->zadd($this->zset($kind), [$member => $scoreMicroseconds]);
    }

    public function cancel(string $kind, string $member): void
    {
        Redis::connection(self::CONNECTION)->zrem($this->zset($kind), $member);
    }

    /**
     * Candidates due at or before `$nowMicroseconds` — an INPUT, so every sweep
     * (and every test) names its clock instead of reading the wall (C-11 at the
     * seam; the DB re-check inside the sweep stays the authority regardless).
     *
     * @return list<string> members, "{tenant_id}|{id}"
     */
    public function due(string $kind, int $nowMicroseconds, int $limit = 500): array
    {
        /** @var list<string> $members */
        $members = Redis::connection(self::CONNECTION)->zrangebyscore(
            $this->zset($kind),
            '-inf',
            (string) $nowMicroseconds,
            ['limit' => ['offset' => 0, 'count' => $limit]],
        );

        return $members;
    }

    public static function member(string $tenantId, string $id): string
    {
        return $tenantId.'|'.$id;
    }

    /** @return array{0: string, 1: string} [tenant_id, id] */
    public static function parseMember(string $member): array
    {
        [$tenantId, $id] = explode('|', $member, 2);

        return [$tenantId, $id];
    }

    /**
     * FS-01's law: "ZSET lost → rebuild from columns (truth)". The callable
     * yields `member => scoreMicroseconds` rows read from the owning columns;
     * rebuild only ever ADDS candidates at their exact column deadline — it can
     * restore a delayed timer, never invent a premature one, because acting
     * still happens only after the sweep's DB re-check.
     *
     * @param  callable(): iterable<string, int>  $truth
     * @return int members restored
     */
    public function rebuild(string $kind, callable $truth): int
    {
        $restored = 0;
        foreach ($truth() as $member => $score) {
            $this->schedule($kind, $member, $score);
            $restored++;
        }

        return $restored;
    }

    private function zset(string $kind): string
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $kind) !== 1) {
            throw new \InvalidArgumentException('a DueSet kind is a short snake_case name');
        }

        return 'due:'.$kind;
    }
}
