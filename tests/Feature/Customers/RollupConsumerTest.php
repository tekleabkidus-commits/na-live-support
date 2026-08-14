<?php

declare(strict_types=1);

use App\Domain\Customers\Rollups\CustomerRollups;
use App\Jobs\Customers\RollupConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-03 / NFR-05 / FS-03 — rollups: event-maintained, recomputable,
| N-14-excluded, C-09-idempotent under real redelivery
|--------------------------------------------------------------------------
|
| The recompute-from-truth idiom: an event names WHICH customer; every value
| is re-derived. SABOTAGE #12 rewrites the writer into an incrementer and
| the replay proof goes red. SABOTAGE AMENDMENT #16's FIRST CONTROL lives
| here: last_contact_at is a BYTE-COPY of the source message's own DB stamp
| (the inverted-R-19 shape) — switching the writer to now() goes red.
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** @return array{0: App\Models\Tenant, 1: string, 2: string} [tenant, customerId, conversationId] */
function rollupScene(): array
{
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');

    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenant->identifier(),
        'channel' => 'telegram',
        'channel_ref_id' => 'fixture_bot',
        'external_chat_id' => 'chat-rollup-'.Str::random(8),
        'customer_id' => $customerId,
        'state' => 'active',
    ]);

    return [$tenant, $customerId, $conversationId];
}

function rollupTicket(string $tenantId, string $conversationId, string $customerId, string $status): string
{
    $id = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => $status,
        'priority' => 'normal',
        'source' => 'agent',
    ]);

    return $id;
}

it('recomputes every rollup from truth, with N-14 riding the ONE MetricExclusion predicate — spam counts nowhere', function (): void {
    [, $customerId, $conversationId] = rollupScene();
    $tenantId = (string) DB::table('customers')->where('id', $customerId)->value('tenant_id');

    rollupTicket($tenantId, $conversationId, $customerId, 'open');
    rollupTicket($tenantId, $conversationId, $customerId, 'closed');
    rollupTicket($tenantId, $conversationId, $customerId, 'spam');   // N-14: excluded everywhere

    app(CustomerRollups::class)->recompute($customerId);

    $row = DB::table('customers')->where('id', $customerId)->first();
    expect($row->tickets_count)->toBe(2)
        ->and($row->open_tickets)->toBe(1)
        ->and($row->contacts_30d_count)->toBe(2)
        ->and($row->last_contact_at)->toBeNull();
});

it('SABOTAGE-16 CONTROL #1 (inverted R-19): last_contact_at is a BYTE-COPY of the newest customer message\'s own DB stamp — never a fresh clock; agent and quarantined messages are invisible to it', function (): void {
    [, $customerId, $conversationId] = rollupScene();
    $tenantId = (string) DB::table('customers')->where('id', $customerId)->value('tenant_id');

    $first = reopenCustomerMessage($tenantId, $conversationId);
    $second = reopenCustomerMessage($tenantId, $conversationId);

    // Later AGENT and QUARANTINED rows must not move the needle.
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'out', 'author_type' => 'agent', 'kind' => 'text', 'body' => 'agent reply', 'is_internal' => false, 'delivery_state' => 'queued',
    ]);
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text', 'body' => 'quarantined', 'is_internal' => false, 'quarantined' => true,
    ]);

    app(CustomerRollups::class)->recompute($customerId);

    // BYTE equality with the SOURCE stamp, both read back as raw text —
    // the write is a copy of messages.created_at, not "roughly the same
    // moment". A now() writer diverges here by construction.
    $sourceStamp = (string) DB::table('messages')->where('id', $second)->value('created_at');
    $written = (string) DB::table('customers')->where('id', $customerId)->value('last_contact_at');

    expect($written)->toBe($sourceStamp)
        ->and($written)->not->toBe((string) DB::table('messages')->where('id', $first)->value('created_at'));
});

it('C-09: the consumer is idempotent on event_id — a redelivery recomputes the same truth into the same state (sabotage #12\'s subject)', function (): void {
    [, $customerId, $conversationId] = rollupScene();
    $tenantId = (string) DB::table('customers')->where('id', $customerId)->value('tenant_id');

    rollupTicket($tenantId, $conversationId, $customerId, 'open');
    $messageId = reopenCustomerMessage($tenantId, $conversationId);

    /** @var App\Models\Conversation $conversation */
    $conversation = App\Models\Conversation::query()->findOrFail($conversationId);
    $eventId = reopenSeedEvent($conversation, $messageId);

    (new RollupConsumer($eventId))->handle(app(CustomerRollups::class));
    $afterFirst = (array) DB::table('customers')->where('id', $customerId)
        ->first(['tickets_count', 'open_tickets', 'contacts_30d_count', 'last_contact_at']);

    // The at-least-once world: the SAME event id delivered again.
    (new RollupConsumer($eventId))->handle(app(CustomerRollups::class));
    $afterSecond = (array) DB::table('customers')->where('id', $customerId)
        ->first(['tickets_count', 'open_tickets', 'contacts_30d_count', 'last_contact_at']);

    expect($afterSecond)->toBe($afterFirst)
        ->and($afterFirst['tickets_count'])->toBe(1)
        ->and($afterFirst['last_contact_at'])->not->toBeNull();
});

it('resolves the ticket.* family\'s customer from the TICKET ROW (truth), never the payload', function (): void {
    [, $customerId, $conversationId] = rollupScene();
    $tenantId = (string) DB::table('customers')->where('id', $customerId)->value('tenant_id');
    $ticketId = rollupTicket($tenantId, $conversationId, $customerId, 'open');

    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'ticket.status_changed', 'schema_version' => 1,
        'payload' => json_encode([
            'tenant_id' => $tenantId, 'ticket_id' => $ticketId, 'conversation_id' => $conversationId,
            'old_status' => 'new', 'new_status' => 'open', 'actor_type' => 'staff', 'actor_id' => null,
        ], JSON_THROW_ON_ERROR),
    ]);

    (new RollupConsumer($eventId))->handle(app(CustomerRollups::class));

    expect(DB::table('customers')->where('id', $customerId)->value('open_tickets'))->toBe(1);
});

it('NFR-05: drift() names a manufactured non-decay drift, recompute heals it, and clean state answers empty', function (): void {
    [, $customerId, $conversationId] = rollupScene();
    $tenantId = (string) DB::table('customers')->where('id', $customerId)->value('tenant_id');
    rollupTicket($tenantId, $conversationId, $customerId, 'open');

    $rollups = app(CustomerRollups::class);
    $rollups->recompute($customerId);
    expect($rollups->drift($customerId))->toBe([]);

    // Manufacture the drift a missed event would leave.
    DB::table('customers')->where('id', $customerId)->update(['tickets_count' => 7]);

    expect($rollups->drift($customerId))->toBe(['tickets_count']);

    $rollups->recompute($customerId);
    expect($rollups->drift($customerId))->toBe([])
        ->and(DB::table('customers')->where('id', $customerId)->value('tickets_count'))->toBe(1);
});
