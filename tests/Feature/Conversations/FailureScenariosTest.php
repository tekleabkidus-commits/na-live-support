<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\InboundMedia;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SendResult;
use App\Domain\Conversations\Media\MediaOutcome;
use App\Domain\Conversations\Media\MediaPipeline;
use App\Domain\Conversations\Messages\MessageKind;
use App\Domain\Conversations\Outbound\ChannelHealthTracker;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Outbound\LaneWorker;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Jobs\Conversations\ConfirmBatchTail;
use App\Jobs\Conversations\FetchInboundMedia;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| FS-01 / FS-02 / FS-03 — the failure drills (Doc 04 S20 step 9c)
|--------------------------------------------------------------------------
|
| Failure scenarios are tests, not prose. FS-01: total channel outage →
| queued accumulates, lanes intact, channel.health.changed fires on the
| transition (A-4), automatic drain on recovery with zero loss in order.
| FS-02: object-storage outage → text unaffected, media held "attachment
| pending" with retries, recovery stores; ×3 → terminal failed + staff
| marker (alert routing → Doc 18 bucket). FS-03: media backlog cannot touch
| the text pipeline — queue-family isolation, structural and behavioral
| (autoscale signal wiring → Doc 18 bucket).
*/

function failureFixture(): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);

    $adapter = new FakeChannelAdapter(new Capabilities(
        maxText: 4096,
        mediaTypes: ['photo', 'voice'],
        supportsTyping: false,
        supportsEdit: false,
        supportsDelete: false,
        supportsButtons: false,
        replyWindow: ReplyWindow::none(),
    ));

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', $adapter, 'webchat-in', 'webchat-out');
    }

    return [$adapter, app(ConversationRepository::class)];
}

function failureConversation(ConversationRepository $repo, string $chat): Conversation
{
    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_fs', 'is_shell' => true]);

    return $repo->findOrCreateForInbound('fakechan', 'bot-1', $chat, $customer->identifier());
}

function failureCompose(Conversation $conversation, string $body): Message
{
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', $body);
    bindTenant($tenantId);

    return $message;
}

// ── FS-01: total channel outage → accumulate, health event, drain ────────────

test('FS-01: outage accumulates in queued with the lane intact, health goes down on the streak, recovery drains in order with zero loss', function () {
    [$adapter, $repo] = failureFixture();
    $conversation = failureConversation($repo, 'chat-outage');
    $t0 = CarbonImmutable::now('UTC');

    $m1 = failureCompose($conversation, 'outage-first');
    $m2 = failureCompose($conversation, 'outage-second');
    $m3 = failureCompose($conversation, 'outage-third');

    // The outage: every send answers channel_unavailable (retryable).
    foreach (range(1, 3) as $i) {
        $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::ChannelUnavailable)));
    }

    // Three attempts on the head, spaced past each backoff (1s, 5s).
    app(LaneWorker::class)->processOnce($t0);
    app(LaneWorker::class)->processOnce($t0->addSeconds(2));
    app(LaneWorker::class)->processOnce($t0->addSeconds(8));

    // Mid-outage truths: the head retries, everything behind ACCUMULATES in
    // queued, nothing goes final, nothing overtakes (lane intact).
    expect($m1->refresh()->delivery_state)->toBe('failed_retryable')
        ->and($m2->refresh()->delivery_state)->toBe('queued')
        ->and($m3->refresh()->delivery_state)->toBe('queued')
        ->and(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'channel.health.changed')->value('payload'))->toContain('"status": "down"')
        ->and(DB::table('audit_log')->where('action_key', 'channel.health_changed')->count())->toBe(1);

    // Recovery: the adapter answers normally again; cycles drain the lane.
    app(LaneWorker::class)->processOnce($t0->addSeconds(60));  // m1 sends → health up
    app(LaneWorker::class)->processOnce($t0->addSeconds(61));  // m2
    app(LaneWorker::class)->processOnce($t0->addSeconds(62));  // m3

    // Zero loss, order preserved: every row sent; the adapter saw the failed
    // head attempts then the ordered drain — never m2/m3 before m1's success.
    $bodies = array_map(fn ($outbound): string => (string) $outbound->payload->body, $adapter->sent);

    expect($m1->refresh()->delivery_state)->toBe('sent')
        ->and($m2->refresh()->delivery_state)->toBe('sent')
        ->and($m3->refresh()->delivery_state)->toBe('sent')
        ->and($bodies)->toBe([
            'outage-first', 'outage-first', 'outage-first',      // the three outage attempts
            'outage-first', 'outage-second', 'outage-third',     // the recovery drain, in order
        ])
        ->and(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(2)
        // Ordered by UUIDv7 id, NOT occurred_at: `now()` is transaction-start
        // time in Postgres, so every row written inside one transaction shares
        // an occurred_at and cannot order itself (C-11, the S1 finding).
        ->and(DB::table('outbox')->where('name', 'channel.health.changed')->orderByDesc('id')->value('payload'))->toContain('"status": "up"');
});

test('FS-01: the health signal is transition-only — a second outage failure does not re-announce, and non-channel failures never count', function () {
    [$adapter, $repo] = failureFixture();
    $tracker = app(ChannelHealthTracker::class);

    // Three channel-class failures → ONE down announcement…
    $tracker->observeFailure('fakechan', 'bot-1', DeliveryFailureReason::ChannelUnavailable);
    $tracker->observeFailure('fakechan', 'bot-1', DeliveryFailureReason::NetworkError);
    $tracker->observeFailure('fakechan', 'bot-1', DeliveryFailureReason::ChannelUnavailable);
    // …and a fourth changes nothing.
    $tracker->observeFailure('fakechan', 'bot-1', DeliveryFailureReason::ChannelUnavailable);

    expect(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(1);

    // Conversation- and payload-class failures say nothing about the channel.
    $tracker->observeFailure('fakechan', 'bot-2', DeliveryFailureReason::BlockedByUser);
    $tracker->observeFailure('fakechan', 'bot-2', DeliveryFailureReason::InvalidContent);
    $tracker->observeFailure('fakechan', 'bot-2', DeliveryFailureReason::WindowExpired);
    $tracker->observeFailure('fakechan', 'bot-2', DeliveryFailureReason::RateLimited);
    $tracker->observeFailure('fakechan', 'bot-2', DeliveryFailureReason::BlockedByUser);
    $tracker->observeFailure('fakechan', 'bot-2', DeliveryFailureReason::InvalidContent);

    expect(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(1);

    // Recovery announces up exactly once; further successes stay silent.
    $tracker->observeSuccess('fakechan', 'bot-1');
    $tracker->observeSuccess('fakechan', 'bot-1');

    expect(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(2);
});

// ── FS-02: object storage outage ─────────────────────────────────────────────

/** Point the media disk at a dead endpoint (nothing listens on 9 — TCP refused). */
function breakMediaStorage(): void
{
    config(['filesystems.disks.media.endpoint' => 'http://127.0.0.1:9']);
    Storage::forgetDisk('media');
}

function restoreMediaStorage(string $endpoint): void
{
    config(['filesystems.disks.media.endpoint' => $endpoint]);
    Storage::forgetDisk('media');
}

function fsMediaMessage(Conversation $conversation, string $url): Message
{
    $inbound = new NormalizedInbound(
        externalChatId: $conversation->external_chat_id,
        externalMessageId: 'ext-fs02-'.uniqid(),
        sender: new InboundSender('user-1', 'Fixture Customer', '@fixture_fs'),
        kind: MessageKind::Document,
        body: null,
        media: [new InboundMedia(kind: MessageKind::Document, sourceUrl: $url, mime: 'application/pdf', bytes: null)],
        replyToExternalMessageId: null,
        channelMeta: [],
        occurredAt: CarbonImmutable::parse('2026-08-05T10:00:00+00:00'),
        dedupeKey: 'fake:fs02:'.uniqid(),
    );

    return app(MessageRepository::class)->persistInbound($conversation, $inbound, (string) $conversation->customer_id);
}

test('FS-02: storage outage → text unaffected, media held "attachment pending" with retry, recovery stores clean', function () {
    [$adapter, $repo] = failureFixture();
    $conversation = failureConversation($repo, 'chat-storage');
    $now = CarbonImmutable::now('UTC');

    // Fixture bytes uploaded while storage is HEALTHY (they live on MinIO and
    // are fetched via the egress proxy — the FETCH side stays up in FS-02).
    $key = 'fixtures/'.uniqid().'/doc.pdf';
    Storage::disk('media')->put($key, '%PDF-1.4 fixture document');
    $url = Storage::disk('media')->temporaryUrl($key, $now->addMinutes(10));
    $endpoint = (string) config('filesystems.disks.media.endpoint');

    $message = fsMediaMessage($conversation, $url);

    breakMediaStorage();

    try {
        // Attempt 1 under the outage: held, "attachment pending" — the item is
        // `scanning` with the attempt recorded, never lost, never stored.
        $outcome = app(MediaPipeline::class)->process($message, 0, $now);

        expect($outcome)->toBe(MediaOutcome::Held);
        $item = $message->refresh()->media[0];
        expect($item['scan_status'])->toBe('scanning')
            ->and($item['scan_attempts'])->toBe(1)
            ->and($item['storage_key'] ?? null)->toBeNull();

        // Inbound TEXT is unaffected while storage is down (it never touches
        // the object store): the full pipeline persists a text message.
        Queue::fake([ConfirmBatchTail::class, FetchInboundMedia::class]);
        $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
        Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', [
            'update_id' => 77_101,
            'chat_id' => 'chat-storage',
            'message_id' => 'ext-fs02-text',
            'from' => ['id' => 'user-1', 'display' => 'Fixture Customer'],
            'kind' => 'text',
            'text' => 'text flows during the storage outage',
            'occurred_at' => '2026-08-05T10:01:00+00:00',
        ]));
        bindTenant($tenantId);

        expect(Message::query()->where('external_message_id', 'ext-fs02-text')->exists())->toBeTrue();
    } finally {
        restoreMediaStorage($endpoint);
    }

    // Recovery: the SAME item processes to clean + stored on the next attempt.
    $outcome = app(MediaPipeline::class)->process($message, 0, $now->addMinutes(2));

    expect($outcome)->toBe(MediaOutcome::Done);
    $item = $message->refresh()->media[0];
    expect($item['scan_status'])->toBe('clean')
        ->and($item['storage_key'] ?? null)->not->toBeNull()
        ->and(DB::table('outbox')->where('name', 'media.stored')->count())->toBe(1);
});

test('FS-02: a storage outage outlasting x3 goes terminal failed with the staff marker — never a silent loss', function () {
    [$adapter, $repo] = failureFixture();
    $conversation = failureConversation($repo, 'chat-storage-x3');
    $now = CarbonImmutable::now('UTC');

    $key = 'fixtures/'.uniqid().'/doc.pdf';
    Storage::disk('media')->put($key, '%PDF-1.4 fixture document');
    $url = Storage::disk('media')->temporaryUrl($key, $now->addMinutes(10));
    $endpoint = (string) config('filesystems.disks.media.endpoint');

    $message = fsMediaMessage($conversation, $url);

    breakMediaStorage();

    try {
        expect(app(MediaPipeline::class)->process($message, 0, $now))->toBe(MediaOutcome::Held)
            ->and(app(MediaPipeline::class)->process($message, 0, $now->addSeconds(70)))->toBe(MediaOutcome::Held)
            ->and(app(MediaPipeline::class)->process($message, 0, $now->addSeconds(140)))->toBe(MediaOutcome::Done);
    } finally {
        restoreMediaStorage($endpoint);
    }

    $item = $message->refresh()->media[0];
    expect($item['scan_status'])->toBe('failed')
        ->and(Message::query()->where('kind', 'system')
            ->whereRaw("channel_meta->>'system_code' = 'media_failed'")->exists())->toBeTrue();
});

// ── FS-03: media backlog isolation ───────────────────────────────────────────

test('FS-03: a media backlog cannot slow text — jobs land on the isolated media family and text flows while they wait', function () {
    failureFixture();
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();

    Queue::fake([FetchInboundMedia::class, ConfirmBatchTail::class]);

    // A media-carrying inbound: the text row persists NOW; the fetches queue
    // on `media` and (simulating a backlog) are never processed.
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', [
        'update_id' => 78_001,
        'chat_id' => 'chat-backlog',
        'message_id' => 'ext-fs03-media',
        'from' => ['id' => 'user-1', 'display' => 'Fixture Customer'],
        'kind' => 'photo',
        'media' => [
            ['kind' => 'photo', 'url' => 'https://minio:9000/fixture-a.jpg', 'mime' => 'image/jpeg'],
            ['kind' => 'photo', 'url' => 'https://minio:9000/fixture-b.jpg', 'mime' => 'image/jpeg'],
        ],
        'occurred_at' => '2026-08-05T10:02:00+00:00',
    ]));
    bindTenant($tenantId);

    Queue::assertPushed(FetchInboundMedia::class, 2);
    Queue::assertPushedOn('media', FetchInboundMedia::class);

    // With the backlog still unprocessed, a text inbound completes end to end.
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', [
        'update_id' => 78_002,
        'chat_id' => 'chat-backlog',
        'message_id' => 'ext-fs03-text',
        'from' => ['id' => 'user-1', 'display' => 'Fixture Customer'],
        'kind' => 'text',
        'text' => 'text overtakes no queue — it was never behind one',
        'occurred_at' => '2026-08-05T10:03:00+00:00',
    ]));
    bindTenant($tenantId);

    expect(Message::query()->where('external_message_id', 'ext-fs03-text')->sole()->body)
        ->toBe('text overtakes no queue — it was never behind one')
        ->and(Message::query()->where('external_message_id', 'ext-fs03-media')->sole()->media)->toHaveCount(2);
});

test('FS-03: the isolation is supervisor physics — every queue family has its own supervisor and media shares with nobody', function () {
    $supervisors = (array) config('horizon.defaults');

    $families = [];
    foreach ($supervisors as $name => $definition) {
        $queues = (array) ($definition['queue'] ?? []);
        expect($queues)->toHaveCount(1, "supervisor {$name} must own exactly one family (ADR 0007)");
        $families[] = $queues[0];
    }

    expect($families)->toContain('media')
        ->and(array_count_values($families)['media'])->toBe(1)
        ->and($families)->toContain('webchat-in')
        ->and($families)->toContain('telegram-in');
});
