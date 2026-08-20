<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Ai\OcrReceiptConsumer;
use App\Jobs\Ai\TranscribeVoiceConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-05: the normalize consumers over Doc 04's T-05 hooks — transcript and
 * OCR write-back through the repository's own media door; FS-03's outage
 * law (item untouched, the throw IS the retry path); C-09 idempotency.
 */
afterEach(function (): void {
    config(['app.env' => 'testing']);
});

function mediaMessageFixture(string $kind): array
{
    $tenant = createTenant();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();

    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram']);
    $conversation = anotherConversation($tenantId, 'med', $customerId);

    $messageId = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $messageId, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId,
        'kind' => $kind, 'body' => null,
        'media' => json_encode([['kind' => $kind === 'voice' ? 'voice' : 'photo', 'status' => 'clean']]),
    ]);

    return [$tenantId, $messageId];
}

function mediaEvent(string $tenantId, string $messageId, string $name): string
{
    $id = (string) Str::uuid7();
    $conversationId = (string) DB::table('messages')->where('id', $messageId)->value('conversation_id');
    DB::table('events')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'name' => $name,
        'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $messageId, 'conversation_id' => $conversationId, 'index' => 0, 'storage_key' => 't/fixture/media-0']),
    ]);

    return $id;
}

function fakeTier(string $provider, ProviderResponse|Throwable ...$responses): FakeDriver
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    foreach ($responses as $response) {
        $driver->queue($response);
    }
    app(ProviderRegistry::class)->swap($provider, $driver);
    config(['ai.default_routing.fast' => [['provider' => $provider, 'model' => 'norm-model']]]);

    return $driver;
}

it('the guard extends to MEDIA egress: outside production a real media reference is REFUSED — FS-03 absence, by the PII law', function (): void {
    [$tenantId, $messageId] = mediaMessageFixture('voice');
    $driver = fakeTier('fake-stt-guarded', new ProviderResponse('never reached', 1, 1));
    Illuminate\Support\Facades\Cache::flush();
    $eventId = mediaEvent($tenantId, $messageId, 'media.needs_transcript');

    $threw = false;
    try {
        (new TranscribeVoiceConsumer($eventId))->handle(app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Conversations\Storage\MessageRepository::class));
    } catch (AllProvidersExhausted) {
        $threw = true;
    }

    expect($threw)->toBeTrue('non-production media egress was NOT refused — the Doc 05 PII law has a media-shaped hole')
        ->and($driver->seen)->toBe([], 'the payload reached the driver past the guard');
    $media = json_decode((string) DB::table('messages')->where('id', $messageId)->value('media'), true);
    expect($media[0])->not->toHaveKey('transcript_ref');
});

it('FR-05: a voice note gains its transcript_ref through the Doc 04 door; a redelivery changes nothing (C-09)', function (): void {
    config(['app.env' => 'production']); // the carve-out: real media lawfully flows ONLY here
    [$tenantId, $messageId] = mediaMessageFixture('voice');
    fakeTier('fake-stt', new ProviderResponse('[FIXTURE] where is my withdrawal please', 50, 20), new ProviderResponse('SECOND RUN MUST NOT LAND', 1, 1));
    $eventId = mediaEvent($tenantId, $messageId, 'media.needs_transcript');

    (new TranscribeVoiceConsumer($eventId))->handle(app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Conversations\Storage\MessageRepository::class));

    $media = json_decode((string) DB::table('messages')->where('id', $messageId)->value('media'), true);
    expect($media[0]['transcript_ref']['text'])->toBe('[FIXTURE] where is my withdrawal please');

    (new TranscribeVoiceConsumer($eventId))->handle(app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Conversations\Storage\MessageRepository::class));
    $media = json_decode((string) DB::table('messages')->where('id', $messageId)->value('media'), true);
    expect($media[0]['transcript_ref']['text'])->toBe('[FIXTURE] where is my withdrawal please', 'a redelivery overwrote the transcript');
});

it('FR-05: a receipt image gains ocr_ref with per-field confidence', function (): void {
    config(['app.env' => 'production']);
    [$tenantId, $messageId] = mediaMessageFixture('photo');
    fakeTier('fake-ocr', new ProviderResponse((string) json_encode([
        'amount' => ['value' => '2000', 'confidence' => 0.95],
        'currency' => ['value' => 'ETB', 'confidence' => 0.9],
        'reference' => ['value' => 'FIXTURE-TX-001', 'confidence' => 0.85],
    ]), 80, 40));
    $eventId = mediaEvent($tenantId, $messageId, 'media.needs_ocr');

    (new OcrReceiptConsumer($eventId))->handle(app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Conversations\Storage\MessageRepository::class));

    $media = json_decode((string) DB::table('messages')->where('id', $messageId)->value('media'), true);
    expect($media[0]['ocr_ref']['fields']['reference']['value'])->toBe('FIXTURE-TX-001')
        ->and($media[0]['ocr_ref']['fields']['amount']['confidence'])->toBe(0.95);
});

it('FS-03: a provider outage leaves the media item UNTOUCHED and the throw is the retry path', function (): void {
    config(['app.env' => 'production']);
    [$tenantId, $messageId] = mediaMessageFixture('voice');
    $driver = new FakeDriver(new FixturesOnlyEgress);
    app(ProviderRegistry::class)->swap('fake-down', $driver); // empty script: any call throws
    config(['ai.default_routing.fast' => [['provider' => 'fake-down', 'model' => 'x']]]);
    Illuminate\Support\Facades\Cache::flush();
    $eventId = mediaEvent($tenantId, $messageId, 'media.needs_transcript');

    $threw = false;
    try {
        (new TranscribeVoiceConsumer($eventId))->handle(app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Conversations\Storage\MessageRepository::class));
    } catch (AllProvidersExhausted) {
        $threw = true;
    }

    expect($threw)->toBeTrue('the outage was swallowed — the retry path is dead');
    $media = json_decode((string) DB::table('messages')->where('id', $messageId)->value('media'), true);
    expect($media[0])->not->toHaveKey('transcript_ref');
});
