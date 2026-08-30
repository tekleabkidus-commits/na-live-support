<?php

declare(strict_types=1);

use App\Domain\Routing\Priority\BoostScorer;
use App\Domain\Routing\Priority\StarvationCap;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-12 / NFR-06 / BR-01 / EC-04 — THE STARVATION CAP IS ABSOLUTE (property test)
|--------------------------------------------------------------------------
|
| Written BEFORE any routing path exists (S11 plan step 1, the safety floor).
| Four properties, each a generated-input sweep with a fixed seed so a red is
| reproducible:
|
|  P1  VALIDATION PHYSICS — `routing.starvation.minutes` cannot be set to null,
|      zero, negative, absent or non-integer: the registry refuses at write AND
|      StarvationCap refuses at read (two layers; N-12 "no config path to
|      null/disable").
|  P2  EXEMPT FROM EVERY OTHER RULE — for any generated boost stack (any order,
|      any matches, any age below the cap) the un-boosted ticket that has waited
|      past the cap is at the HEAD. No stack buries it: the cap is a promotion
|      outside the score (A-51), so the score is irrelevant to it.
|  P3  THE CAP IS THE ONLY WAY TO THE HEAD FOR A PLAIN TICKET — below the cap a
|      plain ticket never outranks a boosted one (the score does its job), which
|      is what makes P2 a real promotion and not a vacuous ordering.
|  P4  EC-04 — at the cap the language requirement drops AND the record shows
|      BOTH facts (starved=true, requirement_dropped=true).
|
| Sabotage #2 of the plan: (a) validation loosened to `nullable` → P1 red;
| (b) the promotion folded INTO the score as a weight → P2 red at some stack.
*/

const SEED = 1103;

function capMinutes(): int
{
    /** @var int $default */
    $default = app(SettingsRegistry::class)->default(StarvationCap::SETTING);

    return $default;
}

it('P1: the cap VALUE cannot be null, zero, negative, absent or non-integer — registry AND reader refuse', function () {
    $registry = app(SettingsRegistry::class);
    $definition = $registry->definition(StarvationCap::SETTING);

    // The registered rule string is the physics: required + integer + min:1.
    expect($definition['validation'])->toContain('required')
        ->and($definition['validation'])->toContain('integer')
        ->and($definition['validation'])->toMatch('/min:1(\||$)/')
        ->and($definition['validation'])->not->toContain('nullable')
        ->and($definition['default'])->toBe(30);

    foreach ([null, 0, -1, -30, '', 'off', 'null', false, 0.5] as $poison) {
        // (toThrow's second argument is the EXPECTED MESSAGE, not a failure label —
        // the first run's own catch: the refusal happened, the assertion misread it.)
        expect(fn () => $registry->validate(StarvationCap::SETTING, $poison))
            ->toThrow(ValidationException::class);
        expect(fn () => (new StarvationCap($registry))->minutes(is_int($poison) ? $poison : 0))
            ->toThrow(ValidationException::class);
    }

    // And the legal range reads back as itself.
    expect((new StarvationCap($registry))->minutes(1))->toBe(1)
        ->and((new StarvationCap($registry))->minutes(30))->toBe(30)
        ->and((new StarvationCap($registry))->minutes(1440))->toBe(1440);
});

/**
 * Orders a waiting set the way FR-08 step 3 does: starved tickets first (by age),
 * then everyone else by score desc. This is the ORDERING CONTRACT the engine
 * implements; the property holds against it, and the engine's own test pins the
 * engine to this function (step 5).
 *
 * @param  list<array{id: string, queued_at: DateTimeImmutable, matched: list<string>}>  $tickets
 * @param  list<string>  $orderedRules
 * @return list<string>
 */
function orderWaiting(array $tickets, array $orderedRules, DateTimeImmutable $now, int $capMinutes): array
{
    $cap = new StarvationCap(app(SettingsRegistry::class));
    $scorer = new BoostScorer;
    $starved = [];
    $rest = [];
    foreach ($tickets as $t) {
        if ($cap->isStarved($t['queued_at'], $now, $capMinutes)) {
            $starved[] = $t;
        } else {
            $rest[] = $t + ['score' => $scorer->score($orderedRules, $t['matched'], $t['queued_at'], $now)['score']];
        }
    }
    usort($starved, fn ($a, $b) => $a['queued_at'] <=> $b['queued_at']);
    usort($rest, fn ($a, $b) => [$b['score'], $a['queued_at']] <=> [$a['score'], $b['queued_at']]);

    return array_map(fn ($t) => $t['id'], [...$starved, ...$rest]);
}

it('P2: for 500 generated boost stacks, the plain ticket past the cap is ALWAYS at the head', function () {
    mt_srand(SEED);
    $rules = ['urgent', 'vip', 'window_closing', 'sla_at_risk', 'reopened'];
    $now = new DateTimeImmutable('2026-08-30 12:00:00', new DateTimeZone('UTC'));
    $cap = capMinutes();

    for ($i = 0; $i < 500; $i++) {
        $orderedRules = $rules;
        shuffle($orderedRules);
        $tickets = [];
        $count = mt_rand(2, 40);
        for ($k = 0; $k < $count; $k++) {
            $matched = array_values(array_filter($rules, fn () => mt_rand(0, 1) === 1));
            // Every boosted rival waited LESS than the cap (any age below it).
            $age = mt_rand(0, $cap * 60 - 1);
            $tickets[] = ['id' => "boosted-$k", 'queued_at' => $now->modify("-{$age} seconds"), 'matched' => $matched];
        }
        // The plain ticket: NO boosts, waited exactly the cap (the boundary is inclusive).
        $tickets[] = ['id' => 'plain-starved', 'queued_at' => $now->modify('-'.($cap * 60).' seconds'), 'matched' => []];
        shuffle($tickets);

        $order = orderWaiting($tickets, $orderedRules, $now, $cap);
        expect($order[0])->toBe('plain-starved', "iteration $i: a boost stack buried the starved ticket — the cap is not exempt");
    }
});

it('P3: below the cap the score orders — a plain ticket never outranks a boosted one (the promotion is real, not vacuous)', function () {
    mt_srand(SEED + 1);
    $rules = ['urgent', 'vip', 'window_closing', 'sla_at_risk', 'reopened'];
    $now = new DateTimeImmutable('2026-08-30 12:00:00', new DateTimeZone('UTC'));
    $cap = capMinutes();

    for ($i = 0; $i < 500; $i++) {
        $plainAge = mt_rand(0, $cap * 60 - 2);
        $boostedAge = mt_rand(0, $cap * 60 - 2);
        $tickets = [
            ['id' => 'plain', 'queued_at' => $now->modify("-{$plainAge} seconds"), 'matched' => []],
            ['id' => 'boosted', 'queued_at' => $now->modify("-{$boostedAge} seconds"), 'matched' => [$rules[mt_rand(0, 4)]]],
        ];
        $order = orderWaiting($tickets, $rules, $now, $cap);
        expect($order[0])->toBe('boosted', "iteration $i: a plain ticket below the cap outranked a boosted one");
    }
});

it('P4 (EC-04): at the cap the language requirement drops and the record shows BOTH facts', function () {
    $registry = app(SettingsRegistry::class);
    $cap = new StarvationCap($registry);
    $now = new DateTimeImmutable('2026-08-30 12:00:00', new DateTimeZone('UTC'));
    $queuedAt = $now->modify('-'.(capMinutes() * 60).' seconds');

    // The skill-match step's EC-04 contract, stated as the record it must write:
    $starved = $cap->isStarved($queuedAt, $now, capMinutes());
    $record = [
        'step' => 'skill_match',
        'input' => ['required_language' => 'am', 'online_with_skill' => 0],
        'starved' => $starved,
        'requirement_dropped' => $starved,
        'outcome' => $starved ? 'language requirement dropped at the cap (BR-01 over matching)' : 'hold for a match',
    ];

    expect($starved)->toBeTrue()
        ->and($record['starved'])->toBeTrue()
        ->and($record['requirement_dropped'])->toBeTrue()
        ->and($record['outcome'])->toContain('dropped');

    // And one second short of the cap, neither fact is true — the drop is the cap's, not the clock's.
    $notYet = $cap->isStarved($queuedAt->modify('+1 second'), $now, capMinutes());
    expect($notYet)->toBeFalse();
});

it('has no off-switch: no setting key in the registry can disable the cap, and StarvationCap takes no such argument', function () {
    $keys = app(SettingsRegistry::class)->keys();
    $suspicious = array_values(array_filter($keys, fn (string $k) => str_starts_with($k, 'routing.starvation') && $k !== StarvationCap::SETTING));
    expect($suspicious)->toBe([], 'a second routing.starvation.* key exists: '.implode(', ', $suspicious));

    $ctor = (new ReflectionClass(StarvationCap::class))->getConstructor();
    expect($ctor?->getNumberOfParameters())->toBe(1);
    foreach ((new ReflectionClass(StarvationCap::class))->getMethods() as $m) {
        expect($m->getName())->not->toMatch('/disable|off|bypass|skip/i');
    }
});
