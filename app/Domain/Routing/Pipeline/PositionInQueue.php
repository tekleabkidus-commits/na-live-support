<?php

declare(strict_types=1);

namespace App\Domain\Routing\Pipeline;

use App\Domain\Routing\Priority\BoostScorer;
use App\Domain\Routing\Priority\StarvationCap;
use App\Support\Settings\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-08 step 3 / FR-12 / NFR-06 / BR-01 / EC-04 — queue position: base FIFO by
 * queued_at (DB time), boosts from the queue's ORDERED priority_rules (each logged), and the
 * starvation cap evaluated FIRST and OUTSIDE the score (A-51): a ticket past
 * routing.starvation.minutes is promoted to the HEAD regardless of boosts. Both facts are
 * written to the record (EC-04: at the cap the language requirement drops — the skill step
 * reads `starved` from here).
 */
final class PositionInQueue
{
    public function __construct(
        private readonly BoostScorer $scorer,
        private readonly StarvationCap $cap,
        private readonly SettingsRepository $settings,
        private readonly BoostMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $ticket  id, priority, reopened_count, customer_id, queued_at, tags
     * @return array{score: int, rule_score: int, starved: bool, boosts_applied: list<string>, age_seconds: int, cap_minutes: int, matched: list<string>}
     */
    public function compute(string $queueId, array $ticket, ?DateTimeImmutable $now = null): array
    {
        $rulesRaw = DB::table('queues')->where('id', $queueId)->value('priority_rules');
        /** @var list<mixed> $rules */
        $rules = is_string($rulesRaw) ? array_values((array) json_decode($rulesRaw, true)) : [];
        $matched = $this->matcher->matches($ticket);
        // `$now` is DB time (C-11). A caller scoring MANY tickets in one pass (routing:rebuild,
        // FS-01) passes ONE instant for all of them: the age term is whole seconds, so two
        // tickets queued in the same second scored across a clock tick would otherwise swap
        // order — the S11 step-7 RebuildDrillTest red (NFR-03; the fix is one clock per pass).
        $now ??= $this->dbNow();
        $queuedAtRaw = $ticket['queued_at'] ?? null;
        $queuedAt = is_string($queuedAtRaw) ? new DateTimeImmutable($queuedAtRaw) : $now;
        $capMinutes = $this->settings->get(StarvationCap::SETTING);
        $capMinutes = is_int($capMinutes) ? $capMinutes : 30;

        $starved = $this->cap->isStarved($queuedAt, $now, $capMinutes);
        $scored = $this->scorer->score(array_values(array_filter($rules, 'is_string')), $matched, $queuedAt, $now);

        return [
            'score' => $scored['score'],
            // The ZSET score carries the RULE boosts only (S11 step 8): the age term is whole seconds
            // evaluated at each ticket's OWN scoring instant, so two same-second tickets scored across a
            // tick would swap (the EstimatorTest position red); FIFO among equals is the queued_at term.
            'rule_score' => $scored['score'] - min($scored['age_seconds'], 999),
            'starved' => $starved,
            'boosts_applied' => $scored['boosts_applied'],
            'age_seconds' => $scored['age_seconds'],
            'cap_minutes' => $capMinutes,
            'matched' => $matched,
        ];
    }

    public function dbNow(): DateTimeImmutable
    {
        /** @var object{t: string} $clock */
        $clock = DB::selectOne('SELECT clock_timestamp() AS t');

        return new DateTimeImmutable($clock->t, new DateTimeZone('UTC'));
    }
}
