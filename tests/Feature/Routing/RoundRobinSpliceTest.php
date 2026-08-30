<?php

declare(strict_types=1);

use App\Domain\Routing\Strategies\RoundRobin;

/*
| Doc 11 FR-09 / T-04 / EC-02 / AC-02 — RR fairness over join/leave/skip sequences. The pointer is
| a USER ID: the next turn is the first routable agent after it in user-id order, wrapping; a
| join or leave changes the ring, never the pointer's place; an Away agent is skipped (absent
| from the routable set) and keeps their turn for when they return.
*/
function ring(array $ids): array
{
    return array_map(fn (string $id) => ['user_id' => $id, 'capacity' => 5, 'load' => 0, 'free' => 5], $ids);
}

it('rotates fairly, splices on join/leave without resetting, and skips an Away agent who keeps their turn (T-04 sequences)', function () {
    createTenant();
    $department = departmentFixture('RR');
    $queue = (string) Illuminate\Support\Facades\DB::table('departments')->where('id', $department)->value('default_queue_id');
    $rr = app(RoundRobin::class);
    $a = '00000000-0000-7000-8000-00000000000a';
    $b = '00000000-0000-7000-8000-00000000000b';
    $c = '00000000-0000-7000-8000-00000000000c';
    $d = '00000000-0000-7000-8000-00000000000d';

    expect($rr->order($queue, ring([$a, $b, $c]), [])['candidates'])->toBe([$a, $b, $c]);
    $rr->advance($queue, $a);
    expect($rr->order($queue, ring([$a, $b, $c]), [])['candidates'])->toBe([$b, $c, $a]);
    $rr->advance($queue, $b);
    // JOIN: d joins — the pointer stays at b; the ring is spliced, next is c then d then a then b.
    expect($rr->order($queue, ring([$a, $b, $c, $d]), [])['candidates'])->toBe([$c, $d, $a, $b]);
    $rr->advance($queue, $c);
    // SKIP (EC-02): d goes Away (absent from the routable set) — next is a, not a reset to the start.
    expect($rr->order($queue, ring([$a, $b, $c]), [])['candidates'])->toBe([$a, $b, $c]);
    $rr->advance($queue, $a);
    // d returns: the turn after a is b, then c, then d — d's turn was kept, nothing was reset.
    expect($rr->order($queue, ring([$a, $b, $c, $d]), [])['candidates'])->toBe([$b, $c, $d, $a]);
    // LEAVE: b leaves while the pointer is on a — next is c.
    expect($rr->order($queue, ring([$a, $c, $d]), [])['candidates'])->toBe([$c, $d, $a]);
    // Wrap: pointer past the last id.
    $rr->advance($queue, $d);
    expect($rr->order($queue, ring([$a, $c, $d]), [])['candidates'])->toBe([$a, $c, $d]);
    // The DB checkpoint mirrors the pointer (FS-01 fallback).
    expect(Illuminate\Support\Facades\DB::table('queues')->where('id', $queue)->value('rr_pointer_user_id'))->toBe($d);
});
