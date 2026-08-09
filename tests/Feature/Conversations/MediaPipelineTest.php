<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\InboundMedia;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Media\ClamdClient;
use App\Domain\Conversations\Media\MediaFetcher;
use App\Domain\Conversations\Media\MediaOutcome;
use App\Domain\Conversations\Media\MediaPipeline;
use App\Domain\Conversations\Media\MediaStore;
use App\Domain\Conversations\Messages\MessageKind;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Jobs\Conversations\GenerateThumbnails;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Media chain (Doc 04 S20 step 5 — AC-05, T-04/T-05, SEC-02/03/04, BR-06,
| NFR-05, EC-02/EC-11, FR-10 step 5)
|--------------------------------------------------------------------------
|
| REAL integrations, not mocks: fixture bytes are uploaded to MinIO and fetched
| back THROUGH the egress proxy; the scan is the real clamd INSTREAM (EICAR
| detects); the store is the real S3 disk. The one stand-in is EC-11's dead
| clamd — a ClamdClient pointed at a closed port, the QuotaTest "real closed
| port" pattern.
*/

function mediaFixture(): Conversation
{
    $tenant = createTenant();
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice'],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_media', 'is_shell' => true]);

    return app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-media-'.uniqid(), $customer->identifier());
}

/** Upload fixture bytes to MinIO and return a proxy-fetchable signed URL. */
function mediaFixtureUrl(string $name, string $bytes): string
{
    $key = 'fixtures/'.uniqid().'/'.$name;
    Storage::disk('media')->put($key, $bytes);

    return Storage::disk('media')->temporaryUrl($key, CarbonImmutable::now('UTC')->addMinutes(10));
}

function mediaMessage(Conversation $conversation, MessageKind $kind, string $mime, string $url): Message
{
    $inbound = new NormalizedInbound(
        externalChatId: $conversation->external_chat_id,
        externalMessageId: 'ext-media-'.uniqid(),
        sender: new InboundSender('user-1', 'Fixture Customer', '@fixture_media'),
        kind: $kind,
        body: null,
        media: [new InboundMedia(kind: $kind, sourceUrl: $url, mime: $mime, bytes: null)],
        replyToExternalMessageId: null,
        channelMeta: [],
        occurredAt: CarbonImmutable::parse('2026-08-01T10:00:00+00:00'),
        dedupeKey: 'fake:media:'.uniqid(),
    );

    return app(MessageRepository::class)->persistInbound($conversation, $inbound, (string) $conversation->customer_id);
}

/** A JPEG with a handcrafted EXIF APP1 segment (Artist tag) for BR-06's proof. */
function jpegWithExif(): string
{
    $image = imagecreatetruecolor(64, 64);
    imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, 200, 60, 60));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    // Minimal valid EXIF APP1: TIFF header + IFD0 with one Artist (0x013B) entry.
    $artist = "fixture\0";
    $tiff = "II*\0".pack('V', 8)                      // little-endian, IFD0 at offset 8
        .pack('v', 1)                                  // one entry
        .pack('v', 0x013B).pack('v', 2)                // Artist, ASCII
        .pack('V', strlen($artist)).pack('V', 26)      // count, value offset
        .pack('V', 0)                                  // next IFD: none
        .$artist;
    $app1 = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

    // Splice APP1 right after SOI.
    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

// ── AC-05: the three fixtures, each landing in its specified terminal state ──

test('AC-05 clean photo: scanned, EXIF-stripped (BR-06), stored tenant-prefixed, events + OCR hook + thumbnails', function () {
    $conversation = mediaFixture();
    Queue::fake([GenerateThumbnails::class]);

    $fixture = jpegWithExif();
    // Positive control at the byte level (no ext-exif in the image): the APP1
    // Exif segment and the Artist value are present in the INPUT bytes.
    expect(str_contains($fixture, "Exif\0\0"))->toBeTrue()
        ->and(str_contains($fixture, 'fixture'))->toBeTrue();

    $message = mediaMessage($conversation, MessageKind::Photo, 'image/jpeg', mediaFixtureUrl('photo.jpg', $fixture));

    $outcome = app(MediaPipeline::class)->process($message, 0, CarbonImmutable::now('UTC'));

    expect($outcome)->toBe(MediaOutcome::Done);

    $item = $message->refresh()->media[0];
    expect($item['scan_status'])->toBe('clean')
        ->and($item['storage_key'])->toStartWith('t/'.$conversation->getAttribute('tenant_id').'/')
        ->and(DB::table('outbox')->where('name', 'media.stored')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'media.needs_ocr')->count())->toBe(1);

    // BR-06: the STORED object carries no EXIF — no APP1 marker, no Artist
    // value; the metadata died in the GD re-encode.
    $stored = (string) Storage::disk('media')->get($item['storage_key']);
    expect(str_contains($stored, "Exif\0\0"))->toBeFalse()
        ->and(str_contains($stored, 'fixture'))->toBeFalse();

    // T-04: thumbnails ride a queued job; run it and the ≤3 sizes exist.
    Queue::assertPushed(GenerateThumbnails::class, 1);
    $job = Queue::pushed(GenerateThumbnails::class)->first();
    app()->call([$job, 'handle']);

    $item = $message->refresh()->media[0];
    expect($item['thumbs'])->toHaveCount(3)
        ->and(Storage::disk('media')->exists($item['thumb_key']))->toBeTrue();

    // SEC-02/T-04: a clean item mints a signed URL scoped to this tenant.
    expect(app(MediaStore::class)->signedUrl($item['storage_key'], 'clean'))->toContain($item['storage_key']);
});

test('AC-05 EICAR: infected — event with signature, bytes never stored, and NO download path for any role (SEC-04)', function () {
    $conversation = mediaFixture();

    $message = mediaMessage($conversation, MessageKind::Photo, 'image/jpeg', mediaFixtureUrl('eicar.bin', EICAR));

    $outcome = app(MediaPipeline::class)->process($message, 0, CarbonImmutable::now('UTC'));

    $item = $message->refresh()->media[0];

    expect($outcome)->toBe(MediaOutcome::Done)
        ->and($item['scan_status'])->toBe('infected')
        ->and($item['storage_key'] ?? null)->toBeNull() // never stored
        ->and(DB::table('outbox')->where('name', 'media.infected')->value('payload'))->toContain('Eicar');

    // SEC-04 as an absence: no scan_status but `clean` can mint a URL.
    foreach (['infected', 'pending', 'scanning', 'failed', 'rejected', 'quarantined_pending'] as $status) {
        expect(fn () => app(MediaStore::class)->signedUrl('t/x/whatever', $status))->toThrow(DomainException::class);
    }
});

test('AC-05 oversized (EC-02): the stream aborts MID-DOWNLOAD, item rejected, staff marker + customer notice delivered', function () {
    $conversation = mediaFixture();
    $tenantId = app(TenantContext::class)->tenantId();

    app(SettingsRepository::class)->set('media.max_mb', 1, tenantSurface: false);

    // 1.5 MB against a 1 MB cap — and NO Content-Length trust: the fixture URL
    // serves real bytes, the counter crosses the cap mid-stream.
    $message = mediaMessage($conversation, MessageKind::Photo, 'image/jpeg', mediaFixtureUrl('big.bin', random_bytes(1_572_864)));

    $outcome = app(MediaPipeline::class)->process($message, 0, CarbonImmutable::now('UTC'));
    bindTenant($tenantId); // the customer-notice compose ran its lane job inline

    $item = $message->refresh()->media[0];

    expect($outcome)->toBe(MediaOutcome::Done)
        ->and($item['scan_status'])->toBe('rejected')
        // EC-02's staff half: a system marker in the thread, code not copy (N-03).
        ->and(Message::query()->where('kind', 'system')->count())->toBe(1)
        ->and(Message::query()->where('kind', 'system')->sole()->channel_meta['system_code'])->toBe('media_rejected')
        // EC-02's customer half: an outbound row through the ordinary path.
        ->and(Message::query()->where('direction', 'out')->where('author_type', 'system')->count())->toBe(1);
});

// ── EC-11 / NFR-05: clamd down — held, then quarantined, never delivered ─────

function deadClamdPipeline(): MediaPipeline
{
    return new MediaPipeline(
        app(MediaFetcher::class),
        app(App\Domain\Conversations\Media\SourceUrlResolvers::class), // A-26 seam
        new ClamdClient('clamav', 9999, 1), // real closed port — nothing listens
        app(MediaStore::class),
        app(MessageRepository::class),
        app(EventPublisher::class),
        app(ComposeOutbound::class),
        app(SettingsRepository::class),
        15,
    );
}

test('EC-11: clamd down → held in scanning; the third unavailable attempt quarantines with a staff notice (NFR-05 ×3)', function () {
    $conversation = mediaFixture();
    $pipeline = deadClamdPipeline();
    $now = CarbonImmutable::now('UTC');

    $message = mediaMessage($conversation, MessageKind::Photo, 'image/jpeg', mediaFixtureUrl('held.jpg', jpegWithExif()));

    expect($pipeline->process($message, 0, $now))->toBe(MediaOutcome::Held)
        ->and($message->refresh()->media[0]['scan_status'])->toBe('scanning')
        ->and($pipeline->process($message, 0, $now->addMinutes(1)))->toBe(MediaOutcome::Held);

    // Attempt 3: quarantined_pending, staff marker, still NOTHING stored.
    expect($pipeline->process($message, 0, $now->addMinutes(2)))->toBe(MediaOutcome::Done);

    $item = $message->refresh()->media[0];
    expect($item['scan_status'])->toBe('quarantined_pending')
        ->and($item['storage_key'] ?? null)->toBeNull()
        ->and(Message::query()->where('kind', 'system')->sole()->channel_meta['system_code'])->toBe('media_quarantined_pending');
});

test('EC-11: the 15-minute hold cap quarantines even before three attempts', function () {
    $conversation = mediaFixture();
    $pipeline = deadClamdPipeline();
    $now = CarbonImmutable::now('UTC');

    $message = mediaMessage($conversation, MessageKind::Photo, 'image/jpeg', mediaFixtureUrl('held2.jpg', jpegWithExif()));

    expect($pipeline->process($message, 0, $now))->toBe(MediaOutcome::Held);

    // The SECOND attempt arrives 16 minutes later: the hold clock, not the
    // attempt counter, fires (EC-11's "up to 15 min").
    expect($pipeline->process($message, 0, $now->addMinutes(16)))->toBe(MediaOutcome::Done)
        ->and($message->refresh()->media[0]['scan_status'])->toBe('quarantined_pending');
});

// ── T-05: voice hook ─────────────────────────────────────────────────────────

test('T-05: a clean voice note emits media.needs_transcript with its storage key — the transcriber is Doc 09, not us', function () {
    $conversation = mediaFixture();

    $message = mediaMessage($conversation, MessageKind::Voice, 'audio/ogg', mediaFixtureUrl('note.ogg', random_bytes(2048)));

    app(MediaPipeline::class)->process($message, 0, CarbonImmutable::now('UTC'));

    expect($message->refresh()->media[0]['scan_status'])->toBe('clean')
        ->and(DB::table('outbox')->where('name', 'media.needs_transcript')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'media.needs_ocr')->count())->toBe(0);
});

// ── SEC-03: the egress proxy IS the allowlist ────────────────────────────────

test('SEC-03: a non-allowlisted host is refused BY THE PROXY; the allowlisted store is reachable (positive control)', function () {
    mediaFixture();
    $fetcher = app(MediaFetcher::class);

    // Positive control: allowlisted MinIO serves the fixture through the proxy.
    $stream = $fetcher->fetch(mediaFixtureUrl('ok.bin', 'proxy-ok'), 1024 * 1024);
    expect(stream_get_contents($stream))->toBe('proxy-ok');

    // mailpit is DELIBERATELY absent from docker/egress-proxy/filter: tinyproxy
    // answers 403 Filtered and no byte of it ever reaches PHP.
    expect(fn () => $fetcher->fetch('http://mailpit:8025/', 1024 * 1024))
        ->toThrow(RuntimeException::class, 'SEC-03');
});
