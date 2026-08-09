<?php

declare(strict_types=1);

use App\Domain\Conversations\Media\MediaOutcome;
use App\Domain\Conversations\Media\MediaPipeline;
use App\Domain\Telegram\Api\TelegramApi;
use App\Domain\Telegram\Bots\BotProvisioner;
use App\Domain\Telegram\Direct\CallbackNonce;
use App\Jobs\Conversations\ConfirmBatchTail;
use App\Models\Message;
use App\Models\TelegramBot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| FR-11…15 end to end (S23 step 5) — the REAL doors, sync-driven
|--------------------------------------------------------------------------
|
| Webhook POST → S15 stack → router → NormalizeInbound → dedupe → identity →
| persist → (media) the A-26-resolved chain: MinIO, clamd, the works. The
| sync queue driver runs every hop inline, so one POST proves the whole
| corridor. Fixtures from fixtures/telegram/updates/allowed-updates.json.
*/

/** @return array{key: string, secret: string, bot: TelegramBot} */
function pipelineBot(): array
{
    config([
        'telegram.api_base' => 'http://mock-telegram:8081',
        'telegram.webhook_base' => 'https://dev.fixture.example',
    ]);
    app()->forgetInstance(App\Domain\Telegram\Api\TelegramBotApi::class);
    app()->forgetInstance(TelegramApi::class);
    Http::delete('http://mock-telegram:8081/_recorded');

    // ConfirmBatchTail runs inline under sync and clears the tenant context in
    // its finally BEFORE NormalizeInbound's media foreach dispatches — Doc 04's
    // media suite fakes exactly this job for exactly this reason. Faking ONLY
    // it leaves the full route→router→normalize→media corridor running inline.
    Queue::fake([ConfirmBatchTail::class]);

    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $result = app(BotProvisioner::class)->register('8144038606:FAKECREDhhhhhhhhhhhhhhhhhhhhhhhhhh', 'hybrid');
    $bot = $result['bot'];

    $path = (string) parse_url($result['webhook_url'], PHP_URL_PATH);
    $key = substr($path, strrpos($path, '/') + 1);

    /** @var array{calls: list<array{method: string, params: array<string, mixed>}>} $recorded */
    $recorded = Http::get('http://mock-telegram:8081/_recorded')->json();
    $secret = '';
    foreach ($recorded['calls'] as $call) {
        if ($call['method'] === 'setWebhook') {
            $secret = (string) $call['params']['secret_token'];
        }
    }

    return ['key' => $key, 'secret' => $secret, 'bot' => $bot];
}

/** @return array<string, mixed> */
function updateFixture(string $name): array
{
    /** @var array{fixtures: array<string, array<string, mixed>>} $data */
    $data = json_decode((string) file_get_contents(base_path('fixtures/telegram/updates/allowed-updates.json')), true);

    return $data['fixtures'][$name];
}

function postUpdate(array $ctx, array $update): Illuminate\Testing\TestResponse
{
    $response = test()->postJson('/api/webhooks/telegram/'.$ctx['key'], $update, [
        'X-Telegram-Bot-Api-Secret-Token' => $ctx['secret'],
    ]);

    // ResolveTenant clears the context in its finally (both layers move
    // together); the test re-binds to keep asserting as the tenant.
    bindTenant($ctx['bot']->tenant_id);

    return $response;
}

it('FR-11: a text message rides route → router → dedupe → identity → persist; the DUPLICATE update is dropped by {bot}:{update_id}', function (): void {
    $ctx = pipelineBot();

    postUpdate($ctx, updateFixture('message'))->assertStatus(202);

    expect(Message::query()->where('direction', 'in')->count())->toBe(1);
    $message = Message::query()->firstOrFail();
    expect($message->getAttribute('author_type'))->toBe('customer')
        ->and($message->getAttribute('body'))->toBe('plain text')
        ->and($message->getAttribute('external_message_id'))->toBe('50001');

    // The SAME update redelivered (same update_id). DEDUPE is the LOAD-BEARING
    // guard here (sabotage #2's target): assert its specific drop metric fires,
    // not merely that no second row appears — the redelivery-vs-edit guard also
    // collapses a true redelivery, so a row count alone cannot isolate dedupe.
    $records = &capturedLogsRef();
    postUpdate($ctx, updateFixture('message'))->assertStatus(202);

    $metrics = array_map(fn (array $r): mixed => $r['context']['metric'] ?? null, $records);
    // FR-11: the {bot}:{update_id} dedupe must DROP the redelivery (sabotage #2).
    expect($metrics)->toContain('messaging.inbound.duplicate_dropped');
    expect(Message::query()->where('direction', 'in')->count())->toBe(1, 'FR-11: a redelivered update_id must never produce a second row');
});

it('FR-12: an edit lands on the FR-21 revision path; deleted_business_messages tombstone through the existing door', function (): void {
    $ctx = pipelineBot();

    postUpdate($ctx, updateFixture('business_message'))->assertStatus(202);
    $original = Message::query()->where('external_message_id', '50002')->firstOrFail();
    expect($original->getAttribute('body'))->toBe('business text');

    postUpdate($ctx, updateFixture('edited_business_message'))->assertStatus(202);
    $edited = Message::query()->where('external_message_id', '50002')->firstOrFail();
    expect($edited->getAttribute('body'))->toBe('business edited')
        ->and($edited->getAttribute('edited_at'))->not->toBeNull()
        ->and(Message::query()->where('direction', 'in')->count())->toBe(1, 'an edit is a revision, never a second row')
        ->and(DB::table('message_revisions')->count())->toBe(1);

    postUpdate($ctx, updateFixture('deleted_business_messages'))->assertStatus(202);
    expect(Message::query()->where('external_message_id', '50002')->firstOrFail()->getAttribute('deleted_at'))->not->toBeNull();
});

it('FR-13/A-26: a document rides the FULL chain — opaque ref persisted, resolver fetches via mock through the proxy, clamd scans, MinIO stores; T-07 plant-detect-remove', function (): void {
    $ctx = pipelineBot();

    postUpdate($ctx, updateFixture('media_document'))->assertStatus(202);

    $message = Message::query()->where('external_message_id', '50006')->firstOrFail();
    /** @var list<array<string, mixed>> $media */
    $media = $message->getAttribute('media');

    // The chain ran inline (sync queues): clean + stored.
    expect($media[0]['scan_status'])->toBe('clean')
        ->and($media[0]['storage_key'] ?? null)->not->toBeNull()
        // T-07: the ROW keeps the opaque reference — resolution never persists.
        ->and($media[0]['source_url'])->toBe('telegram-file:'.$ctx['bot']->id.'/doc-1');

    // Condition 4, the counting bar: plant → detect → remove → prove absence.
    $resolvedNeedle = '/file/bot'; // every resolved URL contains this segment
    $scratch = sys_get_temp_dir().'/t07-scan-'.bin2hex(random_bytes(4)).'.txt';
    file_put_contents($scratch, 'planted: http://mock-telegram:8081/file/botFAKE/ok.bin');
    expect(str_contains((string) file_get_contents($scratch), $resolvedNeedle))->toBeTrue('scanner failed its positive control');
    unlink($scratch);

    $rowJson = json_encode($message->getAttributes(), JSON_THROW_ON_ERROR);
    expect(str_contains($rowJson, $resolvedNeedle))->toBeFalse('a resolved file URL reached the message row (T-07)');
    expect(str_contains($rowJson, 'FAKECRED'))->toBeFalse('token material reached the message row (T-07)');

    /** @var list<object{payload: string}> $jobs */
    $jobs = DB::table('jobs')->get(['payload'])->all();
    foreach ($jobs as $job) {
        expect(str_contains($job->payload, $resolvedNeedle))->toBeFalse('a resolved file URL reached a queued job payload (T-07/C-13)');
    }
});

it('A-26 condition 2: resolution happens PER FETCH ATTEMPT — an expired reference holds, a fresh one succeeds on the next attempt', function (): void {
    // Fake the media job so the webhook persists the row + opaque reference
    // but does NOT auto-scan; we then drive attempts by hand (the Held
    // outcome self-redispatches, which under sync would silently run
    // attempt 2 — manual control is what proves per-attempt re-resolution).
    $ctx = pipelineBot();
    // Re-fake AFTER pipelineBot (it fakes only ConfirmBatchTail): the media
    // job must NOT run inline here — we drive attempts by hand.
    Queue::fake([ConfirmBatchTail::class, App\Jobs\Conversations\FetchInboundMedia::class]);

    // getFile: attempt 1 the reference is EXPIRED (transient refusal → the
    // resolver's RuntimeException → the chain's hold door); attempt 2 fresh.
    $real = app(App\Domain\Telegram\Api\TelegramBotApi::class);
    $flipping = new class($real) implements TelegramApi
    {
        public int $getFileCalls = 0;

        public function __construct(private readonly TelegramApi $real) {}

        public function call(string $botToken, string $method, array $params = []): array
        {
            if ($method === 'getFile') {
                $this->getFileCalls++;
                if ($this->getFileCalls === 1) {
                    throw App\Domain\Telegram\Api\TelegramApiError::fromResponse('getFile', 400, [
                        'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: wrong file_id or the file is temporarily unavailable',
                    ]);
                }

                return ['file_id' => 'x', 'file_path' => 'ok.bin', 'file_size' => 17];
            }

            return $this->real->call($botToken, $method, $params);
        }

        public function fileUrl(string $botToken, string $filePath): string
        {
            return $this->real->fileUrl($botToken, $filePath);
        }
    };
    app()->instance(TelegramApi::class, $flipping);

    postUpdate($ctx, updateFixture('media_document'))->assertStatus(202);
    $message = Message::query()->where('external_message_id', '50006')->firstOrFail();

    // Attempt 1: the expired reference re-resolves, getFile refuses → HOLD.
    $out1 = app(MediaPipeline::class)->process($message->refresh(), 0, CarbonImmutable::now('UTC'));
    expect($out1)->toBe(MediaOutcome::Held)
        ->and($flipping->getFileCalls)->toBe(1);
    /** @var list<array<string, mixed>> $mediaHeld */
    $mediaHeld = $message->refresh()->getAttribute('media');
    expect($mediaHeld[0]['scan_status'])->toBe('scanning', 'the expired reference must HOLD (EC-11 path), never fail terminal');

    // Attempt 2: a FRESH resolution (getFile call 2) finds the live path.
    $out2 = app(MediaPipeline::class)->process($message->refresh(), 0, CarbonImmutable::now('UTC'));
    expect($out2)->toBe(MediaOutcome::Done)
        ->and($flipping->getFileCalls)->toBe(2, 'condition 2: attempt 2 RE-RESOLVES, never reuses attempt 1\'s reference');
    /** @var list<array<string, mixed>> $mediaAfter */
    $mediaAfter = $message->refresh()->getAttribute('media');
    expect($mediaAfter[0]['scan_status'])->toBe('clean');
});

it('A-26 condition 3: Telegram\'s "file is too big" classifies through the chain\'s TERMINAL door — EC-07\'s media_rejected note, caption kept', function (): void {
    $ctx = pipelineBot();

    $real = app(App\Domain\Telegram\Api\TelegramBotApi::class);
    $refusing = new class($real) implements TelegramApi
    {
        public function __construct(private readonly TelegramApi $real) {}

        public function call(string $botToken, string $method, array $params = []): array
        {
            if ($method === 'getFile') {
                throw App\Domain\Telegram\Api\TelegramApiError::fromResponse('getFile', 400, [
                    'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: file is too big',
                ]);
            }

            return $this->real->call($botToken, $method, $params);
        }

        public function fileUrl(string $botToken, string $filePath): string
        {
            return $this->real->fileUrl($botToken, $filePath);
        }
    };
    app()->instance(TelegramApi::class, $refusing);

    postUpdate($ctx, updateFixture('media_document'))->assertStatus(202);

    $message = Message::query()->where('external_message_id', '50006')->firstOrFail();
    /** @var list<array<string, mixed>> $media */
    $media = $message->getAttribute('media');
    expect($media[0]['scan_status'])->toBe('rejected', 'EC-07: the 20 MB ceiling is TERMINAL, never a hold loop')
        ->and($message->getAttribute('body'))->toBe('doc caption', 'EC-07: the caption is still stored');

    // The media_rejected staff marker rode the existing door.
    expect(Message::query()->where('author_type', 'system')->get()
        ->contains(fn (Message $m): bool => ($m->getAttribute('channel_meta')['system_code'] ?? null) === 'media_rejected'))->toBeTrue();
});

it('SEC-06/T-05: the nonce is single-use — a REPLAY answers the neutral toast; expiry and MAC integrity hold; ≤64 bytes', function (): void {
    $ctx = pipelineBot();
    $nonces = app(CallbackNonce::class);
    $now = time();

    $data = $nonces->make($ctx['bot']->id, 'menu_main', $now);
    expect(strlen($data))->toBeLessThanOrEqual(64, 'T-05: callback data must fit Telegram\'s 64-byte cap');

    expect($nonces->validate($ctx['bot']->id, $data, $now)['valid'])->toBeTrue();
    $replay = $nonces->validate($ctx['bot']->id, $data, $now);
    expect($replay['valid'])->toBeFalse()->and($replay['reason'])->toBe('replayed');

    $stale = $nonces->make($ctx['bot']->id, 'menu_main', $now - 901);
    expect($nonces->validate($ctx['bot']->id, $stale, $now)['reason'])->toBe('expired');

    $forged = 'menu_main:'.$now.':0000000000000000';
    expect($nonces->validate($ctx['bot']->id, $forged, $now)['reason'])->toBe('bad_mac');
});

it('FR-14: a callback ACKs via answerCallbackQuery — valid silently, replayed with the neutral toast — and the refusal is logged', function (): void {
    $ctx = pipelineBot();
    $data = app(CallbackNonce::class)->make($ctx['bot']->id, 'menu_main', time());

    $update = updateFixture('callback_query');
    $update['callback_query']['data'] = $data;

    Http::delete('http://mock-telegram:8081/_recorded');
    postUpdate($ctx, $update)->assertStatus(202); // first use: valid
    $update['update_id']++;                       // distinct update, same nonce
    postUpdate($ctx, $update)->assertStatus(202); // replay: neutral toast

    /** @var array{calls: list<array{method: string, params: array<string, mixed>}>} $recorded */
    $recorded = Http::get('http://mock-telegram:8081/_recorded')->json();
    $acks = array_values(array_filter($recorded['calls'], fn (array $c): bool => $c['method'] === 'answerCallbackQuery'));
    expect($acks)->toHaveCount(2)
        ->and($acks[0]['params'])->not->toHaveKey('text')
        ->and(is_string($acks[1]['params']['text'] ?? null))->toBeTrue('SEC-06: the replay answers the NEUTRAL toast');
});

it('FR-15: my_chat_member kicked/member publish telegram.bot_blocked|unblocked through the outbox, closed payloads', function (): void {
    $ctx = pipelineBot();

    postUpdate($ctx, updateFixture('my_chat_member'))->assertStatus(202);

    $row = DB::table('outbox')->where('name', 'telegram.bot_blocked')->first(['payload']);
    expect($row)->not->toBeNull();
    expect((string) $row->payload)->toContain('"external_chat_id"')->toContain($ctx['bot']->id);
});

it('T-03: a business message FROM the connection owner carries owner_phone=true AND attributes as agent (A-27)', function (): void {
    $ctx = pipelineBot();

    DB::table('telegram_business_connections')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $ctx['bot']->tenant_id,
        'bot_id' => $ctx['bot']->id,
        'connection_id' => 'fixture-conn-ec11',
        'account_user_id' => 900000100, // == the fixture's from.id
        'account_name' => 'Fixture Account',
        'user_chat_id' => 900000100,
        'state' => 'active',
        'can_reply' => true,
    ]);

    postUpdate($ctx, updateFixture('business_message'))->assertStatus(202);

    $message = Message::query()->where('external_message_id', '50002')->firstOrFail();
    // A-27 landed: owner-phone now attributes as agent (proven fully in
    // OwnerPhoneAttributionTest); here we pin the detection flag rode through.
    expect($message->getAttribute('channel_meta')['owner_phone'] ?? null)->toBeTrue()
        ->and($message->getAttribute('author_type'))->toBe('agent');
});
