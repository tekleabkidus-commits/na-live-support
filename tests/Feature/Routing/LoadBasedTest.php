<?php

declare(strict_types=1);

use App\Domain\Routing\Strategies\LoadBased;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Doc 11 FR-10 / NFR-03 / AC-02 — least open-assigned → least-recently-assigned → user id, every
| tiebreak documented and deterministic.
*/
it('orders by load, then by last_assigned_at (never-assigned first), then by user id — twice the same', function () {
    $tenant = createTenant();
    $u = fn (string $x) => '00000000-0000-7000-8000-00000000000'.$x;
    // last_assigned_at is the FLAG-F trigger's: it stamps ONLY when open_assigned increases (a backdated
    // UPDATE is refused — the first run of this test proved that). So the stamps are EARNED in sequence:
    // c is assigned first (older stamp), then b (newer); d is never assigned (null); a carries load 2.
    foreach (['a', 'b', 'c', 'd'] as $x) {
        DB::table('users')->insert(['id' => $u($x), 'tenant_id' => $tenant->identifier(), 'name' => $x, 'email' => "lb-$x@fixture.example", 'password' => 'x']);
        // d is BORN with its load: an INSERT never fires the BEFORE UPDATE trigger → no stamp = "never assigned".
        DB::table('agent_assignment_stats')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(), 'user_id' => $u($x), 'open_assigned' => $x === 'd' ? 1 : 0]);
    }
    DB::table('agent_assignment_stats')->where('user_id', $u('c'))->update(['open_assigned' => 1]);   // stamped now
    DB::table('agent_assignment_stats')->where('user_id', $u('b'))->update(['open_assigned' => 1]);   // stamped later
    DB::table('agent_assignment_stats')->where('user_id', $u('a'))->update(['open_assigned' => 2]);   // stamped last, load 2
    $routable = array_map(fn ($x, $load) => ['user_id' => $u($x), 'capacity' => 5, 'load' => $load, 'free' => 5 - $load], ['a', 'b', 'c', 'd'], [2, 1, 1, 1]);
    $first = app(LoadBased::class)->order('q', $routable, []);
    $second = app(LoadBased::class)->order('q', array_reverse($routable), []);
    // d: load 1, never assigned → first; c: load 1, 2 h ago; b: load 1, 5 min ago; a: load 2.
    expect($first['candidates'])->toBe([$u('d'), $u('c'), $u('b'), $u('a')])
        ->and($second['candidates'])->toBe($first['candidates'], 'input order changed the output — non-deterministic');
});
