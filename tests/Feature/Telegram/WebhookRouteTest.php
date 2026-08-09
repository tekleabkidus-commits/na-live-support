<?php

declare(strict_types=1);

use App\Domain\Telegram\Bots\BotProvisioner;
use App\Jobs\Telegram\RouteTelegramUpdate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The REAL webhook route (Doc 05 FR-01/FR-02/SEC-02/T-02 on the S15 stack)
|--------------------------------------------------------------------------
|
| These tests run the FULL production middleware stack on the real route —
| path-borne key, per-key throttle, secret_header verification, tenant
| state — against a bot provisioned through the REAL write path (the
| provisioner talking to mock-telegram through the real egress proxy).
|
| THE ORDER PROOF (T-02, step-4 obligation 4): bot resolution happens
| BEFORE secret verification, proven as behavior, not assumed from the
| declaration — an unknown key with a VALID-SHAPED secret answers 404
| (resolution failed first; the secret was never consulted), while a known
| key with a bad secret answers the byte-identical webhook_rejected 403.
| The declared stack order is ALSO pinned exactly, so a reorder fails a
| named test even before behavior shifts.
*/

const FAKE_STEP4_TOKEN = '8144038606:FAKECREDdddddddddddddddddddddddddd';

/**
 * @return array{key: string, secret: string}
 */
function provisionWebhookBot(): array
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

    $result = app(BotProvisioner::class)->register(FAKE_STEP4_TOKEN, 'business');

    $key = (string) parse_url($result['webhook_url'], PHP_URL_PATH);
    $key = substr($key, strrpos($key, '/') + 1);

    /** @var array{calls: list<array{method: string, params: array<string, mixed>}>} $recorded */
    $recorded = Http::get('http://mock-telegram:8081/_recorded')->json();
    $secret = '';
    foreach ($recorded['calls'] as $call) {
        if ($call['method'] === 'setWebhook') {
            $secret = (string) $call['params']['secret_token'];
        }
    }

    expect($key)->not->toBe('');
    expect($secret)->not->toBe('');

    return ['key' => $key, 'secret' => $secret];
}

it('FR-02: a valid path-key + secret rides the S15 stack to a 202 ACK and the router job — never inline', function (): void {
    ['key' => $key, 'secret' => $secret] = provisionWebhookBot();
    Queue::fake();

    $response = $this->postJson('/api/webhooks/telegram/'.$key, [
        'update_id' => 920840999,
        'message' => ['message_id' => 1, 'date' => 1754700000, 'chat' => ['id' => 5], 'from' => ['id' => 5], 'text' => 'hi'],
    ], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);

    $response->assertStatus(202)->assertJson(['accepted' => true]);
    Queue::assertPushed(RouteTelegramUpdate::class, 1);
    Queue::assertPushed(fn (RouteTelegramUpdate $job): bool => $job->queue === 'telegram-in');
});

it('SEC-02: a wrong secret and a missing secret answer the byte-identical webhook_rejected 403', function (): void {
    ['key' => $key] = provisionWebhookBot();
    Queue::fake();

    $wrong = $this->postJson('/api/webhooks/telegram/'.$key, ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'not-the-secret']);
    $missing = $this->postJson('/api/webhooks/telegram/'.$key, ['update_id' => 1]);

    $wrong->assertStatus(403);
    $missing->assertStatus(403);
    // Byte-identical ACROSS CAUSES: only the per-request trace_id may differ.
    $strip = fn (string $body): string => (string) preg_replace('/"trace_id":"[^"]+"/', '"trace_id":"-"', $body);
    expect($strip((string) $wrong->getContent()))->toBe($strip((string) $missing->getContent()), 'the 403 must be byte-identical across causes (S15 ruling)');
    expect($wrong->json('code'))->toBe('webhook_rejected');
    Queue::assertNothingPushed();
});

it('T-02 ORDER PROOF: an unknown key answers 404 even with a well-formed secret — resolution precedes verification (C-10)', function (): void {
    provisionWebhookBot();
    Queue::fake();

    $response = $this->postJson(
        '/api/webhooks/telegram/aaaabbbbccccdddd.'.str_repeat('ab', 32),
        ['update_id' => 1],
        ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('cd', 32)],
    );

    $response->assertStatus(404);
    Queue::assertNothingPushed();
});

it('T-02: the declared middleware order is exactly the S15 contract with the path shim first', function (): void {
    $route = app('router')->getRoutes()->getByName('foundation.webhook.telegram');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toBe([
        'api',
        'telegram.webhook-key',
        'tenant:telegram_bot_key',
        'webhook.throttle',
        'webhook.auth:secret_header,X-Telegram-Bot-Api-Secret-Token',
        'tenant.state:inbound',
    ]);
});

it('SEC-02 rotation consequence: after re-register, the OLD key answers 404 — revoked keys resolve nothing', function (): void {
    ['key' => $oldKey, 'secret' => $oldSecret] = provisionWebhookBot();

    $bot = App\Models\TelegramBot::query()->firstOrFail();
    app(BotProvisioner::class)->reRegister($bot);
    Queue::fake();

    $response = $this->postJson('/api/webhooks/telegram/'.$oldKey, ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => $oldSecret]);

    $response->assertStatus(404);
    Queue::assertNothingPushed();
});
