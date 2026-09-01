<?php

declare(strict_types=1);

use App\Domain\Macros\PaletteReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Doc 12 NFR-05 / AC-05's budget leg — DEV-HARDWARE INDICATIVE (the RbacBudgetsTest
| shape): palette search at the SPEC'S OWN SCALE (1k macros), measured, printed
| against the 50 ms p95 budget, asserted only against a 10× regression ceiling.
| The load-hardware p-value is Doc 19's [D19]; nothing here claims it.
*/
it('meets NFR-05 indicatively: palette search over 1k macros prints its p50/p95 against the 50 ms budget', function (): void {
    [$tenant, , $agent] = macroScene();
    $words = ['Withdrawal', 'Deposit', 'Bonus', 'Verification', 'Payout', 'Refund', 'Greeting', 'Handover'];
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
            'scope' => $i % 5 === 0 ? 'personal' : 'tenant', 'kind' => $i % 3 === 0 ? 'saved_reply' : 'macro',
            'owner_id' => $i % 5 === 0 ? $agent->identifier() : null,
            'name' => $words[$i % 8]." fixture reply {$i}", 'body' => 'Hi {{customer.name}}, fixture body '.$i,
            'variables' => '["customer.name"]',
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('macros')->insert($chunk);
    }

    $reader = app(PaletteReader::class);
    $timings = [];
    foreach (['wd', 'dep', 'bonus', 'ver', 'pay', 'ref', 'greet', 'hand', 'fixture', ''] as $round => $term) {
        for ($k = 0; $k < 2; $k++) {
            $start = hrtime(true);
            $hits = $reader->search($agent, $term);
            $timings[] = (hrtime(true) - $start) / 1e6;
        }
        expect($hits)->not->toBe([]);
    }
    sort($timings);
    $p50 = $timings[(int) floor(count($timings) * 0.5)];
    $p95 = $timings[(int) floor(count($timings) * 0.95)];
    fwrite(STDERR, sprintf("\n  [dev-hardware-indicative] NFR-05 palette search @1k macros  p50 %6.1f ms  p95 %6.1f ms  budget(p95) 50 ms  %s\n", $p50, $p95, $p95 <= 50 ? 'WITHIN' : 'OVER'));
    // 10× regression ceiling (the RbacBudgetsTest posture): a failure is an order-of-magnitude regression or a loaded host — compare p50 to tell which.
    expect($p95)->toBeLessThanOrEqual(500.0, sprintf('palette search p95 %.1f ms exceeds the 10x ceiling (p50 %.1f ms)', $p95, $p50));
});
