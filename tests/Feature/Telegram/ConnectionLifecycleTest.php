<?php

declare(strict_types=1);

use App\Domain\Telegram\Bots\BotProvisioner;
use App\Domain\Telegram\Connections\ConnectionLifecycle;
use App\Jobs\Conversations\ConfirmBatchTail;
use App\Jobs\Telegram\RouteTelegramUpdate;
use App\Models\Message;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FR-07…10, SEC-04, EC-01/02/03/05/06/11, FS-04 — the connection lifecycle
|--------------------------------------------------------------------------
|
| SEC-04's never-routes proof is FIRST in this file by intent (the plan's
| ordering): a connection can never route until it is proven not to route
| while unconfirmed.
*/

function lifecycleBot(): TelegramBot
{
    config(['telegram.api_base' => 'http://mock-telegram:8081', 'telegram.webhook_base' => 'https://dev.fixture.example']);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramBotApi::class);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramApi::class);
    Http::delete('http://mock-telegram:8081/_recorded');
    Queue::fake([ConfirmBatchTail::class]);

    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    return app(BotProvisioner::class)->register('8144038606:FAKECREDjjjjjjjjjjjjjjjjjjjjjjjjjj', 'business')['bot'];
}

/** @return array<string, mixed> a business_connection update object */
function connectionUpdate(int $ownerId, bool $enabled = true, bool $canReply = true, string $connId = 'conn-life-1'): array
{
    return [
        'update_id' => random_int(1, 999_999_999),
        'business_connection' => [
            'id' => $connId,
            'user' => ['id' => $ownerId, 'is_bot' => false, 'first_name' => 'Owner', 'username' => 'owneracct'],
            'user_chat_id' => $ownerId,
            'date' => 1754700000,
            'is_enabled' => $enabled,
            'can_reply' => $canReply,
            'rights' => ['can_reply' => $canReply],
        ],
    ];
}

function lifecycleAdmin(): string
{
    return (string) App\Models\User::factory()->create()->getKey();
}

function routeLife(TelegramBot $bot, array $update): void
{
    RouteTelegramUpdate::dispatch($bot->id, $update);
    bindTenant($bot->tenant_id);
}

function businessMessage(string $connId, int $fromId, string $text): array
{
    return [
        'update_id' => random_int(1, 999_999_999),
        'business_message' => [
            'message_id' => random_int(1, 999_999),
            'business_connection_id' => $connId,
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Player'],
            'chat' => ['id' => $fromId, 'type' => 'private'],
            'date' => 1754700000,
            'text' => $text,
        ],
    ];
}

it('SEC-04 NEVER-ROUTES (sabotage #8): a business message on an UNCONFIRMED (pending) connection does not route — no conversation, no message', function (): void {
    $bot = lifecycleBot();

    // The connection update arrives → PENDING (awaits admin confirm).
    routeLife($bot, connectionUpdate(ownerId: 800800));
    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->value('state'))->toBe('pending');

    // A player message arrives on the still-unconfirmed connection.
    routeLife($bot, businessMessage('conn-life-1', 555, 'hello while pending'));

    expect(Message::query()->count())->toBe(0, 'SEC-04: an unconfirmed connection must NEVER route a message');
    expect(DB::table('conversations')->count())->toBe(0);
});

it('SEC-04: once the admin CONFIRMS, the same connection routes — active is the gate', function (): void {
    $bot = lifecycleBot();
    routeLife($bot, connectionUpdate(ownerId: 800800));

    $admin = lifecycleAdmin();
    app(ConnectionLifecycle::class)->confirm('conn-life-1', $admin);
    bindTenant($bot->tenant_id);
    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->value('state'))->toBe('active');

    routeLife($bot, businessMessage('conn-life-1', 555, 'hello after confirm'));
    expect(Message::query()->where('direction', 'in')->count())->toBe(1, 'a confirmed connection routes');
});

it('EC-11: the THREE REAL captured updates upsert to ONE row, idempotent by connection_id; a no-change redelivery emits no event', function (): void {
    $bot = lifecycleBot();

    /** @var array{updates: list<array<string, mixed>>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/telegram/updates/business-connection-real-capture.json')), true);
    expect($fixture['updates'])->toHaveCount(3);

    $lifecycle = app(ConnectionLifecycle::class);
    foreach ($fixture['updates'] as $u) {
        $lifecycle->applyUpdate($bot->id, $u['business_connection']);
    }

    // ONE row for the real connection id.
    $rows = DB::table('telegram_business_connections')->where('connection_id', 'E9brjRFqwEexAQAAaQdVUkBf9vs')->get();
    expect($rows)->toHaveCount(1, 'EC-11: three redelivered updates for one connection = one row');

    // Idempotent: the first apply created it (pending, no active/paused/revoked
    // event); the next two were no-change → no events at all.
    $events = DB::table('outbox')->where('name', 'like', 'telegram.connection.%')->count();
    expect($events)->toBe(0, 'a no-state-change redelivery emits no event (EC-11 idempotence)');
});

it('EC-02: two admins racing the connect converge on ONE registry row', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);

    // The same connection update processed twice (two admins, two webhook
    // deliveries) → one row (the unique (tenant, connection_id) index + upsert).
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800)['business_connection']);
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800)['business_connection']);

    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->count())->toBe(1);
});

it('EC-03: can_reply=false saves as PAUSED with inbound still flowing (once confirmed)', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);

    // A read-only grant → paused directly.
    $state = $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800, canReply: false)['business_connection']);
    expect($state)->toBe('paused');
    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->value('can_reply'))->toBeFalse();
    expect(DB::table('outbox')->where('name', 'telegram.connection.paused')->count())->toBe(1);
});

it('EC-01: a wrong-account rejection deletes the pending row so the checklist restarts', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800)['business_connection']);

    $lifecycle->reject('conn-life-1');

    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->exists())->toBeFalse();
});

it('EC-05: the account renames; the connection row updates forward from the next update', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800)['business_connection']);

    $renamed = connectionUpdate(ownerId: 800800)['business_connection'];
    $renamed['user']['first_name'] = 'Renamed';
    $lifecycle->applyUpdate($bot->id, $renamed);

    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->value('account_name'))->toBe('Renamed');
});

it('FR-10 + inherited row 2: a revoke flags open tickets channel_unreachable — a pure column write, NO ticket transition', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800)['business_connection']);
    $lifecycle->confirm('conn-life-1', lifecycleAdmin());
    bindTenant($bot->tenant_id);

    // A conversation on the connection with an open ticket.
    $customerId = (string) App\Models\Customer::query()->create([
        'display_name' => 'Fixture Player', 'username' => '@fixture_life_'.Str::random(6), 'is_shell' => true,
    ])->getKey();
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $bot->tenant_id, 'channel' => 'telegram',
        'channel_ref_id' => $bot->id, 'business_connection_id' => 'conn-life-1',
        'external_chat_id' => '555', 'customer_id' => $customerId, 'state' => 'active',
    ]);
    $ticket = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticket, 'tenant_id' => $bot->tenant_id, 'conversation_id' => $conversationId,
        'customer_id' => $customerId, 'number' => random_int(1, 2_000_000_000),
        'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
    ]);

    $eventsBefore = DB::table('outbox')->where('name', 'ticket.status_changed')->count();

    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800, enabled: false)['business_connection']);
    bindTenant($bot->tenant_id);

    expect(DB::table('tickets')->where('id', $ticket)->value('channel_unreachable'))->toBeTrue();
    // Orthogonality (sabotage #18): the flag flip emits NO transition.
    expect(DB::table('outbox')->where('name', 'ticket.status_changed')->count())
        ->toBe($eventsBefore, 'FR-10/EC-10: flagging channel_unreachable must NOT emit a ticket transition');
    expect(DB::table('tickets')->where('id', $ticket)->value('status'))->toBe('open', 'the ticket status is unchanged by the flag');
});

it('FS-04: >3 transitions/hour collapse notifications into a digest while states stay truthful', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800)['business_connection']);
    $lifecycle->confirm('conn-life-1', lifecycleAdmin());
    bindTenant($bot->tenant_id);

    // Flap active↔paused repeatedly (each flip is a paused|revoked-side notify).
    for ($i = 0; $i < 5; $i++) {
        $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800, canReply: false)['business_connection']); // → paused
        $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800, canReply: true)['business_connection']);  // → active
    }

    $notifications = DB::table('outbox')->where('name', 'telegram.connection.notified')->get();
    $deliveries = $notifications->map(fn ($r): string => json_decode((string) $r->payload, true)['delivery'])->all();

    // The first FLAP_THRESHOLD paused-notifies are immediate, the rest digest.
    expect(in_array('digest', $deliveries, true))->toBeTrue('FS-04: past the threshold, notifications digest');
    expect(in_array('immediate', $deliveries, true))->toBeTrue('the early transitions still notify immediately');

    // States stay truthful: the connection ends ACTIVE (its true last state).
    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->value('state'))->toBe('active');
});

it('NFR-04: connection state reflects an update in ≤5 s (indicative, measured)', function (): void {
    $bot = lifecycleBot();
    $lifecycle = app(ConnectionLifecycle::class);

    $start = hrtime(true);
    $lifecycle->applyUpdate($bot->id, connectionUpdate(ownerId: 800800, canReply: false)['business_connection']);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-life-1')->value('state'))->toBe('paused');
    expect($elapsedMs)->toBeLessThan(5000.0, sprintf('NFR-04: state reflection took %.1f ms, budget 5000 ms', $elapsedMs));
    fwrite(STDERR, sprintf("\n[dev-hardware-indicative] NFR-04 connection-state reflection: %.2f ms (budget 5000 ms) WITHIN\n", $elapsedMs));
});
