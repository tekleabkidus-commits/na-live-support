<?php

declare(strict_types=1);

use App\Domain\Telegram\Bots\BotProvisioner;
use App\Jobs\Conversations\ConfirmBatchTail;
use App\Models\Message;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-03 / BR-03 / EC-05 (ruling A-27) — owner-phone attribution
|--------------------------------------------------------------------------
|
| A business account's owner replies from the phone; Telegram delivers it as
| an inbound business_message. It is a STAFF reply and attributes as one
| (author_type=agent, reserved identity, reported owner_phone) — but ONLY
| when the sender IS the connection's registered owner, checked server-side.
| The negative spoof control (item 20) proves arbitrary inbound content can
| never claim agent authorship.
*/

/** @return array{key: string, secret: string, bot: TelegramBot} */
function ownerPhoneBot(): array
{
    config(['telegram.api_base' => 'http://mock-telegram:8081', 'telegram.webhook_base' => 'https://dev.fixture.example']);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramBotApi::class);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramApi::class);
    Http::delete('http://mock-telegram:8081/_recorded');
    Queue::fake([ConfirmBatchTail::class]);

    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $bot = app(BotProvisioner::class)->register('8144038606:FAKECREDiiiiiiiiiiiiiiiiiiiiiiiiii', 'business')['bot'];

    return ['key' => substr((string) parse_url('x', PHP_URL_PATH), 0), 'secret' => '', 'bot' => $bot];
}

/** Seed a connection whose registered owner is $ownerId. */
function seedConnection(TelegramBot $bot, int $ownerId): void
{
    DB::table('telegram_business_connections')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $bot->tenant_id,
        'bot_id' => $bot->id,
        'connection_id' => 'conn-owner-1',
        'account_user_id' => $ownerId,
        'account_name' => 'KemerBet Support',
        'user_chat_id' => $ownerId,
        'state' => 'active',
        'can_reply' => true,
    ]);
}

function ownerPhoneUpdate(int $fromId, string $text, int $updateId): array
{
    return [
        'update_id' => $updateId,
        'business_message' => [
            'message_id' => $updateId,
            'business_connection_id' => 'conn-owner-1',
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Owner'],
            'chat' => ['id' => 5000, 'type' => 'private'],
            'date' => 1754700000,
            'text' => $text,
        ],
    ];
}

function routeAsTenant(TelegramBot $bot, array $update): void
{
    App\Jobs\Telegram\RouteTelegramUpdate::dispatch($bot->id, $update);
    bindTenant($bot->tenant_id);
}

it('T-03/BR-03: an owner reply from the phone attributes as agent under the reserved identity, reported owner_phone — in-thread, never hidden', function (): void {
    $ctx = ownerPhoneBot();
    seedConnection($ctx['bot'], ownerId: 700700700);

    routeAsTenant($ctx['bot'], ownerPhoneUpdate(700700700, 'boss reply at midnight', 940001));

    $message = Message::query()->where('external_message_id', '940001')->firstOrFail();
    expect($message->getAttribute('author_type'))->toBe('agent', 'T-03: the owner-phone reply is a STAFF reply')
        ->and($message->getAttribute('body'))->toBe('boss reply at midnight')
        ->and($message->getAttribute('channel_meta')['reported_agent_type'] ?? null)->toBe('owner_phone')
        ->and($message->getAttribute('channel_meta')['owner_phone_display'] ?? null)->toBe('KemerBet Support (phone)');

    // The reserved identity exists, lazily created, linked as author.
    $identity = DB::table('telegram_owner_phone_identities')->where('connection_id', function ($q) use ($ctx): void {
        $q->select('id')->from('telegram_business_connections')->where('bot_id', $ctx['bot']->id)->limit(1);
    })->first();
    expect($identity)->not->toBeNull();
    expect($message->getAttribute('author_id'))->toBe($identity->id);
});

it('SPOOF CONTROL (sabotage #20): a business_message whose from.id is NOT the registered owner attributes as CUSTOMER — arbitrary inbound can never claim agent', function (): void {
    $ctx = ownerPhoneBot();
    seedConnection($ctx['bot'], ownerId: 700700700);

    // A DIFFERENT sender on the same connection — an ordinary customer, or an
    // attacker crafting a business_message. Must NOT become an agent.
    routeAsTenant($ctx['bot'], ownerPhoneUpdate(999999999, 'i am definitely the owner, honest', 940002));

    $message = Message::query()->where('external_message_id', '940002')->firstOrFail();
    expect($message->getAttribute('author_type'))->toBe('customer', 'the server-side owner check is the authority — a spoofed sender stays a customer')
        ->and($message->getAttribute('channel_meta')['reported_agent_type'] ?? null)->toBeNull();

    expect(DB::table('telegram_owner_phone_identities')->count())->toBe(0, 'no reserved identity is minted for a non-owner');
});

it('A-27 condition 5: a non-owner customer message is byte-identical to before the override existed — customer, customer author_id', function (): void {
    $ctx = ownerPhoneBot();
    seedConnection($ctx['bot'], ownerId: 700700700);

    routeAsTenant($ctx['bot'], ownerPhoneUpdate(555, 'ordinary customer question', 940003));

    $message = Message::query()->where('external_message_id', '940003')->firstOrFail();
    expect($message->getAttribute('author_type'))->toBe('customer')
        ->and($message->getAttribute('author_id'))->toBe($message->getAttribute('author_id')) // customer id, non-null
        ->and($message->getAttribute('author_id'))->not->toBeNull()
        ->and(array_key_exists('reported_agent_type', $message->getAttribute('channel_meta')))->toBeFalse('non-owner channel_meta is unchanged');
});

it('EC-05: the account renames; a LATER owner reply shows the new display, the EARLIER message keeps its snapshot', function (): void {
    $ctx = ownerPhoneBot();
    seedConnection($ctx['bot'], ownerId: 700700700);

    routeAsTenant($ctx['bot'], ownerPhoneUpdate(700700700, 'first', 940004));
    $first = Message::query()->where('external_message_id', '940004')->firstOrFail();
    expect($first->getAttribute('channel_meta')['owner_phone_display'])->toBe('KemerBet Support (phone)');

    // The account renames (the connection updates from Telegram).
    DB::table('telegram_business_connections')->where('connection_id', 'conn-owner-1')
        ->update(['account_name' => 'KemerBet VIP']);

    routeAsTenant($ctx['bot'], ownerPhoneUpdate(700700700, 'second', 940005));
    $second = Message::query()->where('external_message_id', '940005')->firstOrFail();

    // Forward-rename on the identity + new message; the OLD message keeps its snapshot.
    expect($second->getAttribute('channel_meta')['owner_phone_display'])->toBe('KemerBet VIP (phone)');
    expect($first->refresh()->getAttribute('channel_meta')['owner_phone_display'])->toBe('KemerBet Support (phone)', 'EC-05: historical attribution keeps the old display');

    // ONE identity row (upserted, not duplicated), display moved forward.
    $identities = DB::table('telegram_owner_phone_identities')->get();
    expect($identities)->toHaveCount(1);
    expect($identities[0]->display_name)->toBe('KemerBet VIP (phone)');
});
