<?php

declare(strict_types=1);

use App\Domain\Sms\Routing\PrefixTrie;

/*
| Doc 14 FR-08 / EC-01 / §9 / AC-03 — the prefix trie: LONGEST prefix wins
| whatever the table order says (sabotage (g): first-match instead of longest
| → red); the overlap warning names "+2519 shadows +251".
*/

test('longest prefix wins: +2519 beats +251 for a 2519… number; +251 still answers 2517…; an unknown country matches nothing', function () {
    $trie = new PrefixTrie;
    $trie->insert('+251', 'route-et');
    $trie->insert('+2519', 'route-et-mobile');
    $trie->insert('+254', 'route-ke');

    expect($trie->longest('+251912345678'))->toBe(['route_id' => 'route-et-mobile', 'prefix' => '+2519'])
        ->and($trie->longest('+251712345678'))->toBe(['route_id' => 'route-et', 'prefix' => '+251'])
        ->and($trie->longest('+254712345678'))->toBe(['route_id' => 'route-ke', 'prefix' => '+254'])
        ->and($trie->longest('+441234567890'))->toBeNull();
});

test('sabotage (g) control: insertion ORDER never changes the answer — the longer prefix wins whether inserted first or last', function () {
    $first = new PrefixTrie;
    $first->insert('+2519', 'mobile');
    $first->insert('+251', 'country');
    $last = new PrefixTrie;
    $last->insert('+251', 'country');
    $last->insert('+2519', 'mobile');

    expect($first->longest('+251900001234')['route_id'])->toBe('mobile')
        ->and($last->longest('+251900001234')['route_id'])->toBe('mobile');
});

test('EC-01 / §9: overlaps name every (shadowing, shadowed) pair — "+2519 shadows +251"', function () {
    expect(PrefixTrie::overlaps(['a' => '+251', 'b' => '+2519', 'c' => '+254']))
        ->toBe([['shadowing' => '+2519', 'shadowed' => '+251']])
        ->and(PrefixTrie::overlaps(['a' => '+251', 'c' => '+254']))->toBe([]);
});
