<?php

declare(strict_types=1);

use App\Domain\Telegram\Bots\BotProvisioner;
use App\Models\TelegramBot;
use App\Models\TenantKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Adapters\ScriptedTelegramApi;

/*
|--------------------------------------------------------------------------
| Bot registration & webhook lifecycle (Doc 05 FR-01/FR-03/FR-04, SEC-02)
|--------------------------------------------------------------------------
|
| REAL integrations: the provisioner talks to mock-telegram THROUGH the real
| egress proxy; tenant keys and webhook credentials are the REAL S15 rows
| (this is the write path the probe era only seeded by hand). The one
| stand-in is the token-rejected case (a ScriptedTelegramApi), because the
| mock answers every getMe.
|
| Obligation 3 (step 4): FR-01's allowed_updates asserted VERBATIM against a
| LITERAL list in this file — never against the constant — so an edit to
| either side goes red.
*/

const FAKE_PROV_TOKEN = '8144038606:FAKECREDeeeeeeeeeeeeeeeeeeeeeeeeee';

/** The FR-01 list, LITERAL (see the file docblock). */
const FR01_ALLOWED_UPDATES = [
    'message', 'edited_message', 'callback_query', 'my_chat_member',
    'business_connection', 'business_message', 'edited_business_message',
    'deleted_business_messages',
];

function provisioningFixture(): BotProvisioner
{
    config([
        'telegram.api_base' => 'http://mock-telegram:8081',
        'telegram.webhook_base' => 'https://dev.fixture.example',
    ]);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramBotApi::class);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramApi::class);
    Http::delete('http://mock-telegram:8081/_recorded');

    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    return app(BotProvisioner::class);
}

/** @return list<array{method: string, params: array<string, mixed>}> */
function recordedCalls(string $method): array
{
    /** @var array{calls: list<array{method: string, params: array<string, mixed>}>} $recorded */
    $recorded = Http::get('http://mock-telegram:8081/_recorded')->json();

    return array_values(array_filter($recorded['calls'], fn (array $c): bool => $c['method'] === $method));
}

it('FR-01: register validates via getMe, mints key + credential + bot row, sets the webhook, verifies — allowed_updates VERBATIM', function (): void {
    $provisioner = provisioningFixture();

    $result = $provisioner->register(FAKE_PROV_TOKEN, 'business');

    expect($result['verified'])->toBeTrue('getWebhookInfo did not echo the registered URL');

    $bot = $result['bot'];
    expect($bot->telegram_bot_id)->toBe(8144038606);
    expect($bot->username)->toBe('mockstagingbot');
    expect($bot->mode)->toBe('business');
    expect($bot->key_id)->not->toBeNull();

    // The S15 rows exist: an unrevoked telegram_bot_key + its credential.
    $key = TenantKey::query()->whereKey($bot->key_id)->firstOrFail();
    expect($key->getAttribute('kind'))->toBe('telegram_bot');
    expect($key->getAttribute('revoked_at'))->toBeNull();

    $setWebhooks = recordedCalls('setWebhook');
    expect($setWebhooks)->toHaveCount(1);
    $params = $setWebhooks[0]['params'];

    // Obligation 3: the exact 8-type list, against the LITERAL.
    expect($params['allowed_updates'])->toBe(FR01_ALLOWED_UPDATES, 'FR-01 allowed_updates drifted — a missing type is a silent feature amputation');

    // SEC-02: 256-bit secret, stored only as its hash.
    $secret = (string) $params['secret_token'];
    expect(strlen($secret))->toBe(64);
    $hash = DB::table('webhook_credentials')->where('key_id', $bot->key_id)->value('header_secret_hash');
    expect($hash)->toBe(hash('sha256', $secret));

    // The webhook URL carries the shown-once key.
    expect((string) $params['url'])->toStartWith('https://dev.fixture.example/api/webhooks/telegram/');
});

it('FR-03/SEC-02: re-register ROTATES both credentials — new key, new secret, old key revoked; nothing dropped by design', function (): void {
    $provisioner = provisioningFixture();
    $bot = $provisioner->register(FAKE_PROV_TOKEN, 'business')['bot'];
    $oldKeyId = $bot->key_id;
    $oldHash = DB::table('webhook_credentials')->where('key_id', $oldKeyId)->value('header_secret_hash');

    $result = $provisioner->reRegister($bot);

    expect($result['verified'])->toBeTrue();
    $bot->refresh();
    expect($bot->key_id)->not->toBe($oldKeyId, 'FR-03: re-register must mint a FRESH key (shown-once makes URL reuse impossible)');

    $newHash = DB::table('webhook_credentials')->where('key_id', $bot->key_id)->value('header_secret_hash');
    expect($newHash)->not->toBeNull();
    expect($newHash)->not->toBe($oldHash, 'SEC-02: the webhook secret must ROTATE on every re-register');

    expect(TenantKey::query()->whereKey($oldKeyId)->value('revoked_at'))->not->toBeNull('the superseded key must be revoked in the same transaction');
    expect(recordedCalls('setWebhook'))->toHaveCount(2);
});

it('FR-04: archiving refuses while non-revoked connections exist without the cascade ack; with it, deleteWebhook runs, the key revokes, the ROW SURVIVES', function (): void {
    $provisioner = provisioningFixture();
    $bot = $provisioner->register(FAKE_PROV_TOKEN, 'business')['bot'];

    DB::table('telegram_business_connections')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $bot->tenant_id,
        'bot_id' => $bot->id,
        'connection_id' => 'fixture-conn-1',
        'account_user_id' => 8263702228,
        'account_name' => 'Fixture Account',
        'user_chat_id' => 8263702228,
        'state' => 'active',
        'can_reply' => true,
    ]);

    expect(fn () => $provisioner->archive($bot, cascadeAcknowledged: false))
        ->toThrow(DomainException::class, 'cascade');

    $provisioner->archive($bot, cascadeAcknowledged: true);

    expect(recordedCalls('deleteWebhook'))->toHaveCount(1);
    $bot->refresh();
    expect($bot->status)->toBe('archived');
    expect(TenantKey::query()->whereKey($bot->key_id)->value('revoked_at'))->not->toBeNull();
    expect(TelegramBot::query()->whereKey($bot->id)->exists())->toBeTrue('FR-04: archived, never dropped');
});

it('FR-01: a token whose getMe answers without id/username is rejected — no rows are minted', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $scripted = new ScriptedTelegramApi;
    $scripted->script(['not_id' => true]); // getMe answer with no id/username

    $provisioner = new BotProvisioner($scripted, app(App\Support\Tenancy\TenantKeyIssuer::class));

    expect(fn () => $provisioner->register(FAKE_PROV_TOKEN, 'direct'))
        ->toThrow(DomainException::class, 'not a usable bot token');
    expect(TelegramBot::query()->count())->toBe(0);
});

it('FR-25: registration registers the five fixed commands via setMyCommands PER LANGUAGE — fallback + en + am, localized descriptions, and re-register keeps them', function (): void {
    $provisioner = provisioningFixture();
    $result = $provisioner->register(FAKE_PROV_TOKEN, 'direct');

    $calls = recordedCalls('setMyCommands');
    expect(count($calls))->toBe(3, 'one C-08 fallback call (no language_code) + one per launch language');

    $byScope = [];
    foreach ($calls as $call) {
        $scope = is_string($call['params']['language_code'] ?? null) ? $call['params']['language_code'] : 'default';
        $byScope[$scope] = $call['params']['commands'];
    }
    expect(array_keys($byScope))->toEqualCanonicalizing(['default', 'en', 'am']);

    $five = ['start', 'menu', 'status', 'human', 'language'];
    foreach ($byScope as $scope => $commands) {
        expect(array_column($commands, 'command'))->toBe($five, "the {$scope} scope registers the FIXED v1 set, in order");
        foreach ($commands as $command) {
            expect($command['description'])->toBeString()->not->toBe('');
            expect(strlen($command['description']))->toBeLessThanOrEqual(256, 'Telegram caps descriptions at 256');
        }
    }

    // Localized: the am descriptions are the Amharic strings, not English.
    expect($byScope['am'])->not->toBe($byScope['en'], 'the am scope must carry the AMHARIC descriptions');
    expect((string) $byScope['am'][0]['description'])->toBe(trans('telegram.ui.templates.cmd_start', [], 'am'));
    expect($byScope['default'])->toBe($byScope['en'], 'the unscoped call is the C-08 English fallback');

    // FR-03: rotation re-registers the commands too — nothing drops.
    $provisioner->reRegister($result['bot']);
    expect(count(recordedCalls('setMyCommands')))->toBe(6, 're-register repeats all three scopes (idempotent, safe anytime)');
});
