<?php

declare(strict_types=1);

use App\Domain\Telegram\Api\TelegramApiError;
use App\Domain\Telegram\Api\TelegramBotApi;
use App\Domain\Telegram\Api\TelegramTransportError;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The thin Telegram client (ADR 0012; Doc 05 T-02, SEC-07, T-07, A-19)
|--------------------------------------------------------------------------
|
| REAL integrations, not mocks: calls travel THROUGH the real egress proxy to
| the real mock-telegram container (the Session 0 stub — CI and dev never
| call api.telegram.org). The one stand-in is the broken-proxy case — a
| client pointed at a closed proxy port, the QuotaTest pattern.
|
| THE PIN THAT MATTERS (S23 sabotage #11, advisor-mandated): the dead-proxy
| test targets an api_base that IS directly reachable from this container
| (mock-telegram). If TelegramBotApi ever grows a direct-egress fallback,
| that call SUCCEEDS and the test goes red on the missing exception — the
| step-0 finding (dev topology does not force the proxy) is exactly why the
| pin must be config-behavioral, not topological.
*/

const FAKE_STEP2_TOKEN = '8144038606:FAKECREDbbbbbbbbbbbbbbbbbbbbbbbbbb';

function telegramClientVia(string $proxy): TelegramBotApi
{
    config(['telegram.egress_proxy' => $proxy, 'telegram.api_base' => 'http://mock-telegram:8081']);
    app()->forgetInstance(TelegramBotApi::class);

    return app(TelegramBotApi::class);
}

it('T-02/SEC-07: a call rides the egress proxy to the Bot API and returns the result — token supplied at call time', function (): void {
    $api = telegramClientVia('http://egress-proxy:8888');

    $result = $api->call(FAKE_STEP2_TOKEN, 'sendMessage', ['chat_id' => 42, 'text' => 'proxy-ride probe']);

    expect($result['message_id'] ?? null)->toBeInt();
    expect($result['chat']['id'] ?? null)->toBe(42);
});

it('maps an ok:false answer to TelegramApiError carrying status and description — and NEVER the token', function (): void {
    $api = telegramClientVia('http://egress-proxy:8888');

    try {
        // 'no-such' defeats the mock's \w+ route match -> 404 {ok:false}.
        $api->call(FAKE_STEP2_TOKEN, 'no-such');
        $this->fail('an ok:false answer did not throw');
    } catch (TelegramApiError $e) {
        expect($e->httpStatus)->toBe(404);
        expect($e->description)->toContain('unknown route');
        expect($e->getMessage())->not->toContain(FAKE_STEP2_TOKEN);
    }
});

it('SEC-07 PIN (sabotage #11): a dead proxy means a dead client — a direct-egress fallback would make this call succeed', function (): void {
    // mock-telegram IS directly reachable from here; only the proxy is dead.
    $api = telegramClientVia('http://127.0.0.1:59999');

    try {
        $api->call(FAKE_STEP2_TOKEN, 'sendMessage', ['chat_id' => 1, 'text' => 'must never arrive']);
        $this->fail('the call SUCCEEDED with a dead proxy — a direct-egress fallback exists (SEC-07 violation)');
    } catch (TelegramTransportError $e) {
        expect($e->getMessage())->not->toContain(FAKE_STEP2_TOKEN);
        expect($e->getMessage())->toContain('[token]');
    }
});

it('T-07: fileUrl embeds the token by design and is documented a secret; the transport error never chains the raw failure', function (): void {
    $api = telegramClientVia('http://egress-proxy:8888');

    $url = $api->fileUrl(FAKE_STEP2_TOKEN, 'photos/file_0.jpg');

    expect($url)->toBe('http://mock-telegram:8081/file/bot'.FAKE_STEP2_TOKEN.'/photos/file_0.jpg');

    // And the scrubber (step 1) would refuse it into any log sink — proven in
    // TelegramScrubberPatternsTest; here we only pin the builder's shape.
});

it('A-19: bot_token is ciphertext at rest, decrypts through the model, and never serializes', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $bot = TelegramBot::query()->create([
        'telegram_bot_id' => 8144038606,
        'username' => 'fixturebot',
        'display_name' => 'Fixture Bot',
        'bot_token' => FAKE_STEP2_TOKEN,
        'mode' => 'direct',
    ]);

    /** @var object{bot_token: string} $raw */
    $raw = DB::table('telegram_bots')->where('id', $bot->id)->first(['bot_token']);

    // Ciphertext at rest: the DB value neither equals nor contains the plaintext.
    expect($raw->bot_token)->not->toBe(FAKE_STEP2_TOKEN);
    expect(str_contains($raw->bot_token, 'FAKECRED'))->toBeFalse('the token is stored in cleartext — the A-19 encrypted cast is gone');

    // The cast round-trips for the one legitimate reader (the send path).
    expect($bot->refresh()->bot_token)->toBe(FAKE_STEP2_TOKEN);

    // $hidden holds on top of the cast.
    expect(json_encode($bot->toArray(), JSON_THROW_ON_ERROR))->not->toContain('FAKECRED');
});
