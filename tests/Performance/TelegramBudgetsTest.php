<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Telegram\TelegramAdapter;
use App\Domain\Telegram\Window\WindowSweep;
use App\Domain\Telegram\Window\WindowTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Adapters\ScriptedTelegramApi;

/*
|--------------------------------------------------------------------------
| Doc 05 §12 / NFR-01..03 — the budgets, measured (S23 step 12, the Doc 06
| §12 pattern: dev-hardware-indicative by default at a 5× regression
| ceiling; PERF_BUDGETS_ENFORCE=1 (`make perf`) asserts the spec budget)
|--------------------------------------------------------------------------
|
| Measured HERE: NFR-01's two adapter overheads (normalization ≤30 ms,
| send our-half ≤80 ms — the ScriptedTelegramApi returns instantly, so
| Telegram RTT is excluded by construction, exactly as the budget is
| written); the §12 ACK budget on the REAL webhook route (p99 ≤250 ms,
| single-request cost); the sweep at 10k armed / 20 due (pro-rated from
| §12's 50k ≤10 s ⇒ 2 s for 10k), with O(due) as the structural claim.
|
| HONEST Doc 19 BUCKETS (not measured here, stated): the SUSTAINED
| NFR-02/SS-02 burst (100 updates/s × 5 min, queue metrics as evidence)
| needs the L1 load harness; the FULL 50k-window sweep needs the L1 data
| profile; Telegram RTT monitoring is Doc 15/18 dashboard work.
*/

it('NFR-01: adapter normalization overhead ≤30 ms (dev-hardware-indicative; RTT excluded by construction)', function (): void {
    $adapter = new TelegramAdapter(
        api: new ScriptedTelegramApi,
        capabilities: new Capabilities(
            maxText: 4096, mediaTypes: ['photo', 'voice', 'video', 'audio', 'document', 'sticker'],
            supportsTyping: true, supportsEdit: true, supportsDelete: true, supportsButtons: false,
            replyWindow: ReplyWindow::duration(86_400),
        ),
        botKey: 'perf-bot',
        tokenResolver: static fn (): string => '8144038606:FAKECREDperfperfperfperfperfperf00',
    );

    $update = [
        'update_id' => 424242,
        'message' => [
            'message_id' => 77, 'date' => 1_754_000_000,
            'chat' => ['id' => 900_000_777, 'type' => 'private'],
            'from' => ['id' => 900_000_777, 'first_name' => 'Perf', 'username' => 'fixture_perf'],
            'text' => 'a representative inbound message body for the normalization budget',
        ],
    ];

    $stats = measure(static fn () => $adapter->handleWebhook($update), 500);
    reportBudget('NFR-01 normalization', 30.0, $stats);
    assertBudget('NFR-01 normalization', $stats, 30.0);
});

it('NFR-01: adapter send overhead (our half) ≤80 ms (dev-hardware-indicative; scripted API = zero RTT)', function (): void {
    $api = new ScriptedTelegramApi;
    $adapter = new TelegramAdapter(
        api: $api,
        capabilities: new Capabilities(
            maxText: 4096, mediaTypes: ['photo', 'voice', 'video', 'audio', 'document', 'sticker'],
            supportsTyping: true, supportsEdit: true, supportsDelete: true, supportsButtons: false,
            replyWindow: ReplyWindow::duration(86_400),
        ),
        botKey: 'perf-bot',
        tokenResolver: static fn (): string => '8144038606:FAKECREDperfperfperfperfperfperf00',
    );

    $stats = measure(static fn () => $adapter->send(
        new OutboundMessage('chat-perf', DeliverablePayload::text('a representative outbound reply body')),
    ), 300);
    reportBudget('NFR-01 send (our half)', 80.0, $stats);
    assertBudget('NFR-01 send (our half)', $stats, 80.0);
});

/**
 * The WebhookRouteTest provisioning idiom, file-local so this suite runs
 * alone (Pest file helpers only exist once their file loads).
 *
 * @return array{key: string, secret: string}
 */
function perfWebhookBot(): array
{
    config([
        'telegram.api_base' => 'http://mock-telegram:8081',
        'telegram.webhook_base' => 'https://dev.fixture.example',
    ]);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramBotApi::class);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramApi::class);
    Illuminate\Support\Facades\Http::delete('http://mock-telegram:8081/_recorded');

    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $result = app(App\Domain\Telegram\Bots\BotProvisioner::class)
        ->register('8144038607:FAKECREDperfackperfackperfackperfa0', 'business');

    $key = (string) parse_url($result['webhook_url'], PHP_URL_PATH);
    $key = substr($key, strrpos($key, '/') + 1);

    /** @var array{calls: list<array{method: string, params: array<string, mixed>}>} $recorded */
    $recorded = Illuminate\Support\Facades\Http::get('http://mock-telegram:8081/_recorded')->json();
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

it('§12: webhook ACK single-request cost p99 ≤250 ms on the REAL route (sustained 100/s burst = Doc 19 L1 bucket)', function (): void {
    ['key' => $key, 'secret' => $secret] = perfWebhookBot();
    Queue::fake();

    $payload = ['update_id' => 1, 'message' => ['message_id' => 1, 'date' => 1_754_000_000,
        'chat' => ['id' => 900_000_778, 'type' => 'private'], 'from' => ['id' => 900_000_778, 'first_name' => 'Ack'],
        'text' => 'ack budget probe']];

    $i = 0;
    $stats = measure(function () use ($key, $secret, $payload, &$i): void {
        $payload['update_id'] = ++$i;
        test()->postJson('/api/webhooks/telegram/'.$key, $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret,
        ])->assertStatus(202);
    }, 100);
    reportBudget('§12 webhook ACK', 250.0, $stats);
    assertBudget('§12 webhook ACK', $stats, 250.0);
});

it('NFR-03/§12: sweep at 10k armed / 20 due inside the pro-rated budget — O(due), never O(armed); 50k = Doc 19 bucket', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);
    $tracker = app(WindowTracker::class);

    // 10k ARMED candidates: future scores. The sweep must never touch them
    // (O(due)) — they exist purely to bloat the index.
    $future = (int) DB::selectOne('SELECT ((extract(epoch from clock_timestamp()) + 86400) * 1000000)::bigint AS m')->m;
    $redis = app('redis')->connection('volatile');
    for ($batch = 0; $batch < 10; $batch++) {
        $args = [$tracker->zsetKey()];
        for ($i = 0; $i < 1000; $i++) {
            $args[] = $future + $batch * 1000 + $i;
            $args[] = 'armed-'.$batch.'-'.$i;
        }
        $redis->command('zadd', $args);
    }

    // 20 DUE candidates with REAL conversation rows behind them.
    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Perf Player', 'username' => '@fixture_perf_'.Str::random(6), 'is_shell' => true,
    ]);
    for ($i = 0; $i < 20; $i++) {
        $conversationId = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
            'channel_ref_id' => 'bot-perf', 'external_chat_id' => 'chat-perf-'.$i,
            'customer_id' => $customer->identifier(), 'state' => 'active',
        ]);
        DB::statement("UPDATE conversations SET reply_window_expires_at = clock_timestamp() - interval '1 minute', reply_window_state = 'open' WHERE id = ?", [$conversationId]);
        $expiry = DB::table('conversations')->where('id', $conversationId)->value('reply_window_expires_at');
        $tracker->index($conversationId, (string) $expiry);
    }

    $now = (int) DB::selectOne('SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint AS m')->m;
    $start = hrtime(true);
    $counts = app(WindowSweep::class)->run($now);
    $ms = (hrtime(true) - $start) / 1e6;

    expect($counts['expired'])->toBe(20, 'exactly the 20 due windows transition');
    expect((int) $redis->command('zcard', [$tracker->zsetKey()]))->toBe(10_000, 'the 10k armed members were never touched — O(due)');

    fwrite(STDERR, sprintf(
        "  [dev-hardware-indicative] §12 sweep 10k-armed/20-due: %.0f ms (pro-rated budget 2000 ms from 50k ≤ 10 s) %s\n",
        $ms, $ms <= 2000.0 ? 'WITHIN' : 'OVER',
    ));
    expect($ms)->toBeLessThan(getenv('PERF_BUDGETS_ENFORCE') === '1' ? 2000.0 : 10_000.0);
});
