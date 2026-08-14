<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Customers\Identity\IdentityResolution;
use App\Domain\Customers\Rollups\CustomerRollups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 §12 budgets — dev-hardware-indicative (the house two-tier: 5×
| regression ceiling by default, PERF_BUDGETS_ENFORCE=1 asserts spec).
| Formal-scale halves are [D19] (L2 inbound; 1 M customers) — stated per row.
|--------------------------------------------------------------------------
*/

it('NFR-01 indicatively: the resolver HIT path (every inbound message) inside 0.3 ms×tier — index-only exact match [D19: L2 rates]', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);

    $resolver = app(IdentityResolution::class);
    $sender = new InboundSender('perf-900700', 'Perf Fixture', '@perf_fixture');
    $resolver->resolveInbound('telegram', $sender); // create once; the loop is the HIT path

    $stats = measure(static function () use ($resolver, $sender): void {
        $resolver->resolveInbound('telegram', $sender);
    });

    reportBudget('resolver hit p99 (NFR-01)', assertedBudget(5.0), $stats);
    assertBudget('resolver hit (NFR-01, spec ≤5 ms p99)', $stats, 5.0);
});

it('§12 indicatively: the per-event HANDLER inside 5 ms×tier, and the repair unit reported separately (ruling B) [D19: L2 rates]', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);

    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
        'channel_ref_id' => 'fixture_bot', 'external_chat_id' => 'chat-perf-'.Str::random(6),
        'customer_id' => $customerId, 'state' => 'active',
    ]);
    foreach (range(1, 20) as $i) {
        DB::table('tickets')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
            'conversation_id' => $conversationId, 'customer_id' => $customerId,
            // Doc 06 T-02: ONE open-family ticket per conversation — the
            // first is open, the rest closed (same recompute cost profile).
            'number' => random_int(1, 2_000_000_000), 'status' => $i === 1 ? 'open' : 'closed',
            'priority' => 'normal', 'source' => 'agent',
        ]);
        reopenCustomerMessage($tenant->identifier(), $conversationId);
    }

    // RULING B (step-3 checkpoint): TWO lines, reported separately.
    // (1) The per-EVENT HANDLER — §12's "rollup event handlers ≤ 5 ms each"
    //     line: the full consumer body (event fetch + customer resolution +
    //     recompute), driven by a real seeded event row.
    /** @var App\Models\Conversation $conversation */
    $conversation = App\Models\Conversation::query()->findOrFail($conversationId);
    $eventId = reopenSeedEvent($conversation, reopenCustomerMessage($tenant->identifier(), $conversationId));
    $rollups = app(CustomerRollups::class);

    $handlerStats = measure(static function () use ($eventId, $rollups): void {
        (new App\Jobs\Customers\RollupConsumer($eventId))->handle($rollups);
    });
    reportBudget('rollup HANDLER p99 (§12 line)', assertedBudget(5.0), $handlerStats);
    assertBudget('rollup event handler (§12, spec ≤5 ms each)', $handlerStats, 5.0);

    // (2) The recompute as the weekly repair job's unit of work — §12 names
    //     NO budget for the batch repair; reported informationally against
    //     the same figure so regressions stay visible.
    $repairStats = measure(static fn () => $rollups->recompute($customerId));
    reportBudget('recompute (repair unit, no §12 budget)', assertedBudget(5.0), $repairStats);
});

it('§12 indicatively: the blocking cascade over 50 conversations inside 3000 ms×tier [D19: formal run]', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    foreach (range(1, 50) as $i) {
        anchoredConversation($tenantId, $customerId, 'tg-perf-'.$i, 'bot-'.$i);
    }
    app(App\Domain\Customers\Blocking\BlockCustomer::class)->block($customerId, 'fixture: §12 cascade', $actor->identifier());
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'customer.blocked', 'schema_version' => 1,
        'payload' => json_encode(['tenant_id' => $tenantId, 'customer_id' => $customerId, 'blocked_by' => $actor->identifier()]),
    ]);

    // One shot (not the sampling harness — a cascade is not idempotent-cheap
    // to repeat 250×): wall time for the full 50-conversation cascade.
    $start = hrtime(true);
    (new App\Jobs\Customers\BlockingCascadeConsumer($eventId))->handle(app(App\Domain\Conversations\Actions\ConversationBlocking::class));
    $ms = (hrtime(true) - $start) / 1_000_000;

    fwrite(STDERR, sprintf("  [dev-hardware-indicative] cascade 50 conversations: %.0f ms (spec ≤3000 ms)\n", $ms));
    expect(DB::table('conversations')->where('customer_id', $customerId)->where('state', 'blocked')->count())->toBe(50)
        ->and($ms)->toBeLessThan(assertedBudget(3000.0));
});
