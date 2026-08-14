<?php

declare(strict_types=1);

use App\Domain\Customers\Rollups\CustomerRollups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The p99-tail characterization (step-4 acceptance RULING 1) — attribute the
| handler's 36% p99 miss by MEASUREMENT, never by resemblance to 0d.
|
| (a) CONTROL: a trivial statement measured in the SAME run — if its tail
|     carries the same outliers, the tail is the HOST's, not the handler's.
| (b) INPUT CORRELATION: p99 vs the customer's data volume — a tail that
|     scales with volume is ALGORITHMIC and [D19]'s L2 run would amplify it
|     after Doc 08 can no longer fix it.
|
| Diagnostic: it PRINTS the table and asserts only sanity; the FINDING is
| filed at the checkpoint from the printed figures.
*/

it('RULING 1: trivial-statement control + volume sweep, printed side by side', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);

    // (a) The control: one indexed single-row SELECT, same harness, same run.
    $control = measure(static function (): void {
        DB::selectOne('SELECT 1');
    });
    reportBudget('CONTROL trivial SELECT', 5.0, $control);

    // (b) The sweep: same recompute, growing volume.
    $rollups = app(CustomerRollups::class);
    foreach ([5, 20, 80, 320] as $volume) {
        $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
        $conversationId = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
            'channel_ref_id' => 'fixture_bot', 'external_chat_id' => 'chat-tail-'.Str::random(6),
            'customer_id' => $customerId, 'state' => 'active',
        ]);
        for ($i = 0; $i < $volume; $i++) {
            DB::table('tickets')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
                'conversation_id' => $conversationId, 'customer_id' => $customerId,
                'number' => random_int(1, 2_000_000_000), 'status' => $i === 0 ? 'open' : 'closed',
                'priority' => 'normal', 'source' => 'agent',
            ]);
            reopenCustomerMessage($tenant->identifier(), $conversationId);
        }

        $stats = measure(static fn () => $rollups->recompute($customerId));
        reportBudget(sprintf('recompute @ %3d tickets+msgs', $volume), 5.0, $stats);
    }

    expect($control['p50'])->toBeLessThan(5.0);
});
