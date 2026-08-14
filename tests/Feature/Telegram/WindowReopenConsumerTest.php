<?php

declare(strict_types=1);

use App\Jobs\Telegram\WindowReopenConsumer;
use App\Support\Events\ConsumerRegistry;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FR-24 (ADR 0011) — the reopen-on-inbound window consumer
|--------------------------------------------------------------------------
|
| ConsumerRegistry entry, C-09 idempotent, with an FS-02-style catch-up drill.
*/

/** @return array{conversation: string, message: string, tenant: string, ticket: string, assignee: string} */
function reopenFixture(string $windowState): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $assignee = (string) App\Models\User::factory()->create()->getKey();
    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Reopen Player', 'username' => '@fixture_reopen_'.Str::random(6), 'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversationId = (string) Str::uuid7();
    $ticketId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
        'channel_ref_id' => 'bot-r', 'business_connection_id' => 'conn-r', 'external_chat_id' => 'chat-r',
        'customer_id' => $customer->identifier(), 'state' => 'active', 'open_ticket_id' => $ticketId,
        'reply_window_state' => $windowState,
    ]);
    DB::statement("UPDATE conversations SET reply_window_expires_at = clock_timestamp() - interval '1 minute' WHERE id = ?", [$conversationId]);
    DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'customer_id' => $customer->identifier(), 'number' => random_int(1, 2_000_000_000),
        'status' => 'open', 'priority' => 'normal', 'source' => 'agent', 'assignee_id' => $assignee,
    ]);
    $messageId = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $messageId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customer->identifier(),
        'kind' => 'text', 'body' => 'still there?', 'external_message_id' => 'ext-r',
        'channel_meta' => json_encode(['telegram_date' => 1_600_000_000]),
    ]);

    return ['conversation' => $conversationId, 'message' => $messageId, 'tenant' => $tenant->identifier(), 'ticket' => $ticketId, 'assignee' => $assignee];
}

/** Publish a real message.received event and return its id. */
function publishMessageReceived(string $conversationId, string $messageId): string
{
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
        'name' => 'message.received',
        'payload' => json_encode(['message_id' => $messageId, 'conversation_id' => $conversationId, 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId()]),
    ]);

    return $eventId;
}

it('ADR 0011: WindowReopenConsumer is registered for message.received', function (): void {
    expect(ConsumerRegistry::consumersFor('message.received'))->toContain(WindowReopenConsumer::class);
});

it('FR-24: a customer message on an EXPIRED window reopens it (→ open, recomputed expiry, badges cleared) and notifies the assignee — no ticket transition', function (): void {
    $f = reopenFixture('expired');
    $eventId = publishMessageReceived($f['conversation'], $f['message']);
    $ticketEventsBefore = DB::table('outbox')->where('name', 'ticket.status_changed')->count();

    (new WindowReopenConsumer($eventId))->handle(app(App\Domain\Telegram\Window\WindowTracker::class), app(EventPublisher::class));

    $conv = DB::table('conversations')->where('id', $f['conversation'])->first(['reply_window_state', 'reply_window_expires_at']);
    expect($conv->reply_window_state)->toBe('open', 'FR-24: reopened to open (badges cleared)');
    $secondsOut = (float) DB::selectOne('SELECT extract(epoch from (?::timestamptz - clock_timestamp()))/3600 AS h', [$conv->reply_window_expires_at])->h;
    expect(round($secondsOut))->toBe(24.0, 'expiry recomputed 24h from the message receipt');

    // Assignee notified via the reopened event.
    $reopened = DB::table('outbox')->where('name', 'telegram.window.reopened')->first(['payload']);
    expect($reopened)->not->toBeNull();
    expect(json_decode((string) $reopened->payload, true)['assignee_id'])->toBe($f['assignee']);

    // Orthogonality: the reopen emits NO ticket transition.
    expect(DB::table('outbox')->where('name', 'ticket.status_changed')->count())->toBe($ticketEventsBefore);
});

it('FR-23: an OWNER-PHONE (agent) message does NOT reopen the window — only customer activity does', function (): void {
    $f = reopenFixture('expired');
    // Flip the message to an agent author (owner-phone).
    DB::table('messages')->where('id', $f['message'])->update(['author_type' => 'agent']);
    $eventId = publishMessageReceived($f['conversation'], $f['message']);

    (new WindowReopenConsumer($eventId))->handle(app(App\Domain\Telegram\Window\WindowTracker::class), app(EventPublisher::class));

    expect(DB::table('conversations')->where('id', $f['conversation'])->value('reply_window_state'))
        ->toBe('expired', 'an agent reply must NOT reopen the window (FR-23)');
    expect(DB::table('outbox')->where('name', 'telegram.window.reopened')->count())->toBe(0);
});

it('C-09 + FS-02 catch-up: re-driving the SAME event reopens EXACTLY once — the assignee is notified once', function (): void {
    $f = reopenFixture('expired');
    $eventId = publishMessageReceived($f['conversation'], $f['message']);

    // First delivery.
    (new WindowReopenConsumer($eventId))->handle(app(App\Domain\Telegram\Window\WindowTracker::class), app(EventPublisher::class));
    // Re-drive (the FS-02 catch-up / at-least-once redelivery).
    (new WindowReopenConsumer($eventId))->handle(app(App\Domain\Telegram\Window\WindowTracker::class), app(EventPublisher::class));

    // The window is open (idempotent recompute), and the reopened notification
    // fired ONCE — the second pass saw state 'open', not 'expired'.
    expect(DB::table('conversations')->where('id', $f['conversation'])->value('reply_window_state'))->toBe('open');
    expect(DB::table('outbox')->where('name', 'telegram.window.reopened')->count())->toBe(1, 'C-09: exactly one notification across redeliveries');
});
