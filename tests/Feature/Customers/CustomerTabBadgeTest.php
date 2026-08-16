<?php

declare(strict_types=1);

use App\Domain\Customers\Rg\CustomerTabBadge;
use App\Jobs\Inbox\ListProjectorConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| S08 escalation #3 part 2 (ruled) — the REAL customer tab badge and the
| projector's customer.merged. One brain, two doors; E03 is the contract.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('E03/BR-03: the badge answers FALSE for a viewer without customers.rg_flag.view — absence computed server-side, because the dot itself is the inference channel; the permitted viewer is the positive control', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['rg_flag' => true, 'rg_flag_set_at' => '2026-06-01 12:00:00+00']);
    $conversationId = anchoredConversation($tenantId, $customerId, 'tg-badge-1');

    $unpermitted = ticketActor(['customers.view'], 'S08 Badge Unpermitted');
    $permitted = ticketActor(['customers.view', 'customers.rg_flag.view'], 'S08 Badge Permitted');

    $badge = app(CustomerTabBadge::class);
    expect($badge->for($conversationId, $unpermitted))->toBeFalse('the dot leaked to an unpermitted viewer — E03/BR-03')
        ->and($badge->for($conversationId, $permitted))->toBeTrue('the positive control failed — the badge answers nobody')
        ->and($badge->for(null, $permitted))->toBeFalse();

    // The door: same brain, plus the panel's fetch anchor.
    $answer = test()->actingAs($permitted, 'staff')
        ->getJson("https://localhost/api/staff/customers/tab-badge?conversation_id={$conversationId}");
    $answer->assertOk();
    expect($answer->json('customer'))->toBeTrue()
        ->and($answer->json('customer_id'))->toBe($customerId);

    $blind = test()->actingAs($unpermitted, 'staff')
        ->getJson("https://localhost/api/staff/customers/tab-badge?conversation_id={$conversationId}");
    expect($blind->json('customer'))->toBeFalse();
});

it('NFR-06 THROUGH THE BADGE PATH: a direct flag flip answers FRESH on the next read — the no-cache repository binds this read too', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $conversationId = anchoredConversation($tenantId, $customerId, 'tg-badge-2');
    $viewer = ticketActor(['customers.view', 'customers.rg_flag.view'], 'S08 Badge Fresh');
    $badge = app(CustomerTabBadge::class);

    expect($badge->for($conversationId, $viewer))->toBeFalse();

    DB::table('customers')->where('id', $customerId)->update(['rg_flag' => true, 'rg_flag_set_at' => '2026-06-01 12:00:00+00']);
    expect($badge->for($conversationId, $viewer))->toBeTrue('a cached answer survived the flip — NFR-06 broken on the badge path');

    DB::table('customers')->where('id', $customerId)->update(['rg_flag' => false]);
    expect($badge->for($conversationId, $viewer))->toBeFalse();
});

it('T-03/EC-01: customer.merged recomputes every list row carrying the MERGED id from the survivor ROW — and a REPLAY recomputes identical state (C-09)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);

    $survivor = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $survivor)->update(['display_name' => 'Survivor Name']);
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    DB::table('customers')->where('id', $merged)->update(['display_name' => 'Merged Name']);

    // A list row born under the MERGED customer.
    $conversation = anotherConversation($tenantId, 'badge-merge', $merged);
    [$ticket] = inboxTicketRow($tenantId, $conversation, 'open');
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $merged,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    expect((string) DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->value('customer_id'))->toBe($merged);

    // The merge's own truth: the saga re-points the ticket and tombstones.
    DB::table('tickets')->where('id', $ticket->identifier())->update(['customer_id' => $survivor]);
    DB::table('conversations')->where('id', $conversation->identifier())->update(['customer_id' => $survivor]);
    DB::table('customers')->where('id', $merged)->update(['merged_into_id' => $survivor]);

    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'customer.merged', 'schema_version' => 1,
        'payload' => json_encode(['tenant_id' => $tenantId, 'survivor_id' => $survivor, 'merged_id' => $merged, 'snapshot_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR),
    ]);
    (new ListProjectorConsumer($eventId))->handle(app(App\Domain\Inbox\ReadModels\ListProjector::class), app(App\Domain\Inbox\ReadModels\ProjectorStatus::class));

    /** @var object{customer_id: string, customer_display_name: ?string} $row */
    $row = DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->first(['customer_id', 'customer_display_name']);
    expect($row->customer_id)->toBe($survivor, 'the list row still carries the tombstone — EC-01\'s live re-point is dead')
        ->and($row->customer_display_name)->toBe('Survivor Name');

    // C-09: the replay recomputes the same state.
    (new ListProjectorConsumer($eventId))->handle(app(App\Domain\Inbox\ReadModels\ListProjector::class), app(App\Domain\Inbox\ReadModels\ProjectorStatus::class));
    expect((string) DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->value('customer_id'))->toBe($survivor)
        ->and(DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->count())->toBe(1);
});

it('the retired doc08-customer-badge seam has NO code path left — the fixture file is gone and the controller reads only the copilot seam', function (): void {
    expect(file_exists(base_path('fixtures/inbox/seams/doc08-customer-badge.json')))->toBeFalse('the retired seam fixture still exists');

    $source = (string) file_get_contents(app_path('Http/Controllers/Inbox/WorkspaceController.php'));
    expect(str_contains($source, 'doc08-customer-badge'))->toBeFalse('the controller still references the retired seam');
});
