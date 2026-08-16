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

it('NFR-04 indicatively: customers_index search round-trip at dev scale (150-customer corpus), the §12 directory figure as anchor [D19: 1 M formal]', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    $viewer = customersScopedActor('All', 'S08 Perf Search');

    $documents = app(App\Domain\Customers\Search\CustomerSearchDocuments::class);
    foreach (range(1, 150) as $i) {
        $id = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
        DB::table('customers')->where('id', $id)->update(['display_name' => 'Perfsearch Customer '.$i]);
        $documents->writeFor($id);
    }

    try {
        $search = app(App\Domain\Customers\Search\CustomerSearch::class);
        $stats = measure(static function () use ($search, $viewer): void {
            $search->search('Perfsearch', $viewer);
        });

        // §12's nearest figure is the directory's 150 ms p95; NFR-04's 1 M
        // profile is [D19] — this row prints the dev-scale reality.
        reportBudget('customers_index search p99 (NFR-04 dev-scale)', assertedBudget(150.0), $stats);
        assertBudget('customers_index search (NFR-04, anchor ≤150 ms)', $stats, 150.0);
    } finally {
        dropCustomersSearchIndex();
    }
});

it('NFR-03/§12 indicatively: the T-07 header build (payload + identities + detected-language) inside the 120 ms p95 server budget [D19: formal profile]', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();

    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $conversationId = anchoredConversation($tenantId, $customerId, 'tg-perf-panel');
    foreach (range(1, 30) as $i) {
        DB::table('customer_identities')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'customer_id' => $i === 1 ? $customerId : enforcedCustomer($tenantId, '2026-06-01 10:01:00+00'),
            'channel' => 'phone', 'external_user_id' => '+2519000090'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'type' => 'phone', 'value' => '+2519000090'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'source' => 'staff',
        ]);
        reopenCustomerMessage($tenantId, $conversationId);
    }

    $payload = app(App\Domain\Customers\Panel\CustomerPayload::class);
    $profile = app(App\Domain\Customers\Profile\CustomerProfile::class);
    $stats = measure(static function () use ($payload, $profile, $customerId): void {
        $payload->build($customerId, false);
        DB::table('customer_identities')->where('customer_id', $customerId)->get();
        $profile->detectedLanguage($customerId);
    });

    reportBudget('panel header build p99 (NFR-03/§12)', assertedBudget(120.0), $stats);
    assertBudget('panel header build (NFR-03, spec ≤120 ms p95)', $stats, 120.0);
});

it('THE RULED MEASUREMENT (escalation #3 part 2): the per-viewer no-cache badge read\'s cost on the workspace render path — printed against Doc 07 NFR-01\'s 1.5 s cold-load budget', function (): void {
    $tenant = createTenant();
    legacyIdentityShapeEnforce();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['rg_flag' => true, 'rg_flag_set_at' => '2026-06-01 12:00:00+00']);
    $conversationId = anchoredConversation($tenantId, $customerId, 'tg-perf-badge');
    $viewer = customersScopedActor('All', 'S08 Perf Badge');
    App\Models\Role::query()->where('name', 'S08 Perf Badge')->update([
        'permissions' => json_encode(['customers.view', 'customers.rg_flag.view']),
    ]);
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($viewer);

    $badge = app(App\Domain\Customers\Rg\CustomerTabBadge::class);
    $stats = measure(static function () use ($badge, $conversationId, $viewer): void {
        $badge->for($conversationId, $viewer);
    });

    // NFR-06's correctness (no cache) versus Doc 07 NFR-01's latency budget:
    // the owner sees the number — an add-on measured in single-digit ms
    // against a 1500 ms cold-load budget is a tuning fact, not a conflict.
    reportBudget('customer tab badge read p99 (vs Doc 07 NFR-01 1500 ms)', assertedBudget(1500.0), $stats);
    assertBudget('customer tab badge read (escalation #3 part 2)', $stats, 1500.0);
});
