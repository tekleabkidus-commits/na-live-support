<?php

declare(strict_types=1);

use App\Domain\Automation\Engine\ConditionTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-02 / §9 — the condition tree: operators × types, nesting, trace
|--------------------------------------------------------------------------
|
| The evaluator returns the PER-CONDITION TRUTH (§9: the ✓/✗ badge with the
| evaluated value is mandatory) — FR-05's dry-run renders THIS trace, so parity
| is by construction. Unknown facts evaluate empty (EC-14's retired-field
| posture); keyword sets resolve the managed list at evaluation.
*/

it('FR-02: the operator matrix over every fact type', function (): void {
    $tree = new ConditionTree;
    $facts = [
        'ticket.status' => 'open', 'ticket.priority' => 'high',
        'ticket.tags' => ['vip', 'deposit'], 'ticket.age_minutes' => 42,
        'customer.language' => 'am', 'message.body' => 'My telebirr deposit of 2,500 ETB is missing',
        'ai.confidence' => 0.83, 'ai.sentiment' => -1,
        'time.within_business_hours' => false, 'time.day_of_week' => 6,
        'customer.custom.player_id' => null,
    ];
    $cases = [
        [['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'open'], true],
        [['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'closed'], false],
        [['fact' => 'ticket.priority', 'op' => 'in', 'value' => ['high', 'urgent']], true],
        [['fact' => 'ticket.tags', 'op' => 'equals', 'value' => 'vip'], true],
        [['fact' => 'ticket.tags', 'op' => 'in', 'value' => ['deposit', 'bonus']], true],
        [['fact' => 'message.body', 'op' => 'contains', 'value' => 'telebirr'], true],
        [['fact' => 'message.body', 'op' => 'contains', 'value' => 'chapa'], false],
        [['fact' => 'ticket.age_minutes', 'op' => 'gt', 'value' => 30], true],
        [['fact' => 'ai.confidence', 'op' => 'lt', 'value' => 0.9], true],
        [['fact' => 'ai.sentiment', 'op' => 'between', 'value' => [-2, 0]], true],
        [['fact' => 'time.day_of_week', 'op' => 'between', 'value' => [6, 7]], true],
        [['fact' => 'customer.custom.player_id', 'op' => 'is_empty'], true],
        [['fact' => 'ticket.status', 'op' => 'is_empty'], false],
        [['fact' => 'nonexistent.fact', 'op' => 'is_empty'], true],
        [['fact' => 'nonexistent.fact', 'op' => 'equals', 'value' => 'x'], false],
        [['fact' => 'time.within_business_hours', 'op' => 'equals', 'value' => false], true],
    ];
    foreach ($cases as [$leaf, $expected]) {
        $result = $tree->evaluate($leaf, $facts);
        expect($result['matched'])->toBe($expected, json_encode($leaf))
            ->and($result['trace'][0]['actual'])->toEqual($facts[$leaf['fact']] ?? null);
    }
});

it('FR-02: nested all/any groups with the full trace — no short-circuit, every badge present (§9)', function (): void {
    $tree = new ConditionTree;
    $facts = ['a' => 1, 'b' => 2, 'c' => 3];
    $node = ['all' => [
        ['fact' => 'a', 'op' => 'equals', 'value' => 1],
        ['any' => [
            ['fact' => 'b', 'op' => 'equals', 'value' => 99],
            ['fact' => 'c', 'op' => 'gt', 'value' => 2],
        ]],
    ]];
    $result = $tree->evaluate($node, $facts);
    expect($result['matched'])->toBeTrue()
        ->and($result['trace'])->toHaveCount(3, 'a short-circuit dropped a badge row (§9 mandates every one)');

    expect($tree->evaluate(['all' => []], $facts)['matched'])->toBeTrue()
        ->and($tree->evaluate(['any' => []], $facts)['matched'])->toBeFalse();
});

it('FR-02: keyword sets resolve the managed list at evaluation; a retired set never matches', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $setId = (string) Str::uuid7();
    DB::table('automation_keyword_sets')->insert([
        'id' => $setId, 'tenant_id' => $tenant->identifier(),
        'name' => 'Deposit words', 'keywords' => json_encode(['telebirr', 'ገንዘብ']),
    ]);
    $tree = new ConditionTree;
    $facts = ['message.body' => 'ገንዘብ አልደረሰኝም']; // carries the keyword exactly ('ገንዘቤ' would not — substring truth, not stemming)

    expect($tree->evaluate(['fact' => 'message.body', 'op' => 'keyword_set', 'value' => $setId], $facts)['matched'])->toBeTrue();

    DB::table('automation_keyword_sets')->where('id', $setId)->update(['active' => false]);
    expect($tree->evaluate(['fact' => 'message.body', 'op' => 'keyword_set', 'value' => $setId], $facts)['matched'])->toBeFalse('a retired set still matches');
});
