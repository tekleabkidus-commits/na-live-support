<?php

declare(strict_types=1);

use App\Domain\Routing\Priority\BoostScorer;
use App\Domain\Routing\Strategies\LoadBased;
use App\Domain\Routing\Waiting\WaitingSets;

/*
|--------------------------------------------------------------------------
| Doc 11 NFR-03 / T-08 / AC-10 / SS-04 — THE DETERMINISM HARNESS (property-based, in CI)
|--------------------------------------------------------------------------
| Generated org snapshots + ticket streams, replayed TWICE through the pure decision functions
| (the strategy ordering, the boost score, the waiting-set score) must produce IDENTICAL
| assignments. Every tiebreak is documented (FR-10: least open → least-recently-assigned → user
| id). A DB-backed replay of a recorded snapshot (the record's `snapshot`) is the same functions
| over the same inputs — this harness IS that replay, seeded, 300 rounds.
*/
const HARNESS_SEED = 1108;

/** A pure simulation of the load-based strategy over an in-memory snapshot (no DB): the documented tiebreaks. */
function simulateLoadBased(array $agents): array
{
    usort($agents, function (array $a, array $b): int {
        if ($a['load'] !== $b['load']) {
            return $a['load'] <=> $b['load'];
        }
        if ($a['last'] !== $b['last']) {
            if ($a['last'] === null) {
                return -1;
            }
            if ($b['last'] === null) {
                return 1;
            }

            return $a['last'] <=> $b['last'];
        }

        return strcmp($a['user_id'], $b['user_id']);
    });

    return array_column($agents, 'user_id');
}

it('T-08: 300 generated snapshots + ticket streams replayed twice yield identical assignment sequences; shuffled input order never changes the output', function () {
    mt_srand(HARNESS_SEED);
    $scorer = new BoostScorer;
    $now = new DateTimeImmutable('2026-08-30 12:00:00', new DateTimeZone('UTC'));
    for ($round = 0; $round < 300; $round++) {
        $n = mt_rand(2, 12);
        $agents = [];
        for ($i = 0; $i < $n; $i++) {
            $agents[] = ['user_id' => sprintf('00000000-0000-7000-8000-%012x', mt_rand(1, 999_999)), 'load' => mt_rand(0, 4), 'capacity' => 5, 'last' => mt_rand(0, 3) === 0 ? null : mt_rand(1_000_000, 2_000_000)];
        }
        $tickets = [];
        $m = mt_rand(1, 20);
        $rules = ['urgent_priority', 'vip', 'window_closing', 'sla_at_risk', 'reopened'];
        shuffle($rules);
        for ($k = 0; $k < $m; $k++) {
            $tickets[] = ['id' => "t$k", 'matched' => array_values(array_filter($rules, fn () => mt_rand(0, 1) === 1)), 'age' => mt_rand(0, 1700)];
        }
        $replay = function () use ($agents, $tickets, $rules, $scorer, $now): array {
            $state = $agents;
            $out = [];
            // Queue order: the waiting-set score, ascending (starved first, then boosts, then FIFO).
            $queue = $tickets;
            usort($queue, function ($a, $b) use ($rules, $scorer, $now): int {
                $sa = $scorer->score($rules, $a['matched'], $now->modify("-{$a['age']} seconds"), $now)['score'];
                $sb = $scorer->score($rules, $b['matched'], $now->modify("-{$b['age']} seconds"), $now)['score'];
                $ka = WaitingSets::score($a['age'] >= 1800, $sa, 1_000_000 - $a['age']);
                $kb = WaitingSets::score($b['age'] >= 1800, $sb, 1_000_000 - $b['age']);

                return $ka <=> $kb ?: strcmp($a['id'], $b['id']);
            });
            foreach ($queue as $t) {
                $eligible = array_values(array_filter($state, fn ($a) => $a['load'] < $a['capacity']));
                $order = simulateLoadBased($eligible);
                if ($order === []) {
                    $out[] = [$t['id'], null];

                    continue;
                }
                $chosen = $order[0];
                $out[] = [$t['id'], $chosen];
                foreach ($state as &$a) {
                    if ($a['user_id'] === $chosen) {
                        $a['load']++;
                        $a['last'] = 3_000_000 + count($out);
                    }
                }
                unset($a);
            }

            return $out;
        };
        $first = $replay();
        $second = $replay();
        expect($second)->toBe($first, "round $round: the replay diverged — a tiebreak is non-deterministic");
        // Input order independence: shuffle the agents and tickets, same outcome.
        shuffle($agents);
        shuffle($tickets);
        $third = $replay();
        expect($third)->toBe($first, "round $round: input order changed the assignments");
    }
});

it('the REAL LoadBased strategy agrees with the documented simulation on the same routable snapshot (the harness is not a second truth)', function () {
    createTenant();
    $snapshot = [];
    for ($i = 0; $i < 6; $i++) {
        $snapshot[] = ['user_id' => sprintf('00000000-0000-7000-8000-%012x', 100 + $i), 'capacity' => 5, 'load' => $i % 3, 'free' => 5 - ($i % 3)];
    }
    $real = app(LoadBased::class)->order('q', $snapshot, [])['candidates'];
    $sim = simulateLoadBased(array_map(fn ($a) => $a + ['last' => null], $snapshot));
    expect($real)->toBe($sim);
});

it('SAME-SECOND TIE (advisor-ordered, S11 step 9): two tickets queued in the same second with identical rules, scored at DIFFERENT instants a tick apart (the live pipeline\'s shape) keep the documented tiebreak — the RULE score only, then queued_at, then id — replayed twice and shuffled', function () {
    $scorer = new BoostScorer;
    $rules = ['urgent_priority', 'vip'];
    $queuedAt = new DateTimeImmutable('2026-08-30 12:00:00', new DateTimeZone('UTC'));
    $tickets = [['id' => 'tb', 'matched' => ['vip']], ['id' => 'ta', 'matched' => ['vip']], ['id' => 'tc', 'matched' => []]];
    $replay = function (array $tickets) use ($scorer, $rules, $queuedAt): array {
        $keys = [];
        foreach ($tickets as $i => $t) {
            // Each ticket scored at its OWN instant: 0, 1, 2 seconds after queueing — the age term differs per ticket.
            $now = $queuedAt->modify(sprintf('+%d seconds', $i));
            $scored = $scorer->score($rules, $t['matched'], $queuedAt, $now);
            $ruleScore = $scored['score'] - min($scored['age_seconds'], 999);   // PositionInQueue::rule_score
            $keys[$t['id']] = WaitingSets::score(false, $ruleScore, (int) $queuedAt->format('U'));
        }
        uksort($keys, fn ($a, $b) => $keys[$a] <=> $keys[$b] ?: strcmp($a, $b));

        return array_keys($keys);
    };
    $first = $replay($tickets);
    expect($first)->toBe(['ta', 'tb', 'tc'], 'vip-boosted first; among equals the id (uuid7 = creation order) decides; the scoring instant must not');
    expect($replay($tickets))->toBe($first);
    shuffle($tickets);
    expect($replay($tickets))->toBe($first, 'input order changed the same-second order');
    // The CONTROL: with the age term left INSIDE the score, the later-scored equal ticket jumps — the very defect the rule_score split removed.
    $withAge = [];
    foreach ([['id' => 'tb', 'matched' => ['vip']], ['id' => 'ta', 'matched' => ['vip']]] as $i => $t) {
        $withAge[$t['id']] = WaitingSets::score(false, $scorer->score($rules, $t['matched'], $queuedAt, $queuedAt->modify(sprintf('+%d seconds', $i)))['score'], (int) $queuedAt->format('U'));
    }
    expect($withAge['ta'] < $withAge['tb'])->toBeTrue('the control did not reproduce the age-term swap');
});
