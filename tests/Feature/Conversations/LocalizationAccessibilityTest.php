<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\InboundMedia;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Messages\LanguageDetector;
use App\Domain\Conversations\Messages\MessageKind;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| §13 / §14 — the data prerequisites (Doc 04 S20 step 10 sweep)
|--------------------------------------------------------------------------
|
| §14: "detected message language stored per message and exposed to Doc 09
| translation features." §13: "images require stored alt slot editable by
| staff for KB reuse."
|
| Both are DATA obligations of this document; the rendering that consumes them
| (live regions, the alt-text editor, the announcement text) is Doc 07's, and
| the traceability rows say so rather than claiming visual coverage.
*/

function l10nFixture(): Conversation
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo'],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create([
        'display_name' => 'Fixture', 'username' => '@fixture_l10n', 'is_shell' => true,
    ]);

    return app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-l10n-'.uniqid(), $customer->identifier());
}

function l10nInbound(Conversation $conversation, ?string $body, array $media = []): App\Models\Message
{
    $inbound = new NormalizedInbound(
        externalChatId: $conversation->external_chat_id,
        externalMessageId: 'ext-l10n-'.uniqid(),
        sender: new InboundSender('user-1', 'Fixture Customer', '@fixture_l10n'),
        kind: $media === [] ? MessageKind::Text : MessageKind::Photo,
        body: $body,
        media: $media,
        replyToExternalMessageId: null,
        channelMeta: [],
        occurredAt: CarbonImmutable::parse('2026-08-05T14:00:00+00:00'),
        dedupeKey: 'fake:l10n:'.uniqid(),
    );

    return app(MessageRepository::class)
        ->persistInbound($conversation, $inbound, (string) $conversation->customer_id);
}

// ── §14: detected language, stored per message ──────────────────────────────

test('§14: the detector separates the two launch scripts and refuses to guess when there are no letters', function () {
    $detector = new LanguageDetector;

    expect($detector->detect('Hello, how do I withdraw?'))->toBe('en')
        ->and($detector->detect('ሰላም፣ ገንዘብ እንዴት ማውጣት እችላለሁ?'))->toBe('am')
        // Mixed content resolves by which script carries more LETTERS — and it
        // is letters, not words: 'ሰላም' (3) loses to 'hello' (5), which is why
        // the majority side of this pair needs a real sentence.
        ->and($detector->detect('ሰላም እንዴት ነህ hello'))->toBe('am')
        ->and($detector->detect('hello ሰላም there friend'))->toBe('en')
        // "Unknown" is honest; a wrong tag is worse than no tag.
        ->and($detector->detect('12345 +251900001234'))->toBeNull()
        ->and($detector->detect('👍🎉'))->toBeNull()
        ->and($detector->detect(''))->toBeNull()
        ->and($detector->detect(null))->toBeNull();
});

test('§14: language is stored per message on BOTH directions of the thread', function () {
    $conversation = l10nFixture();

    $amharic = l10nInbound($conversation, 'ገንዘቤ የት አለ?');
    $english = l10nInbound($conversation, 'Where is my money?');
    $digits = l10nInbound($conversation, '+251900001234');

    $outbound = app(MessageRepository::class)
        ->persistOutbound($conversation, 'agent', null, 'text', 'ገንዘብዎ በመንገድ ላይ ነው።');

    expect($amharic->language)->toBe('am')
        ->and($english->language)->toBe('en')
        ->and($digits->language)->toBeNull()
        ->and($outbound->language)->toBe('am');
});

// ── §13: the staff-editable alt slot ────────────────────────────────────────

test('§13: every stored image carries an alt slot, and staff can write it without touching what the scan decided', function () {
    $conversation = l10nFixture();

    $message = l10nInbound($conversation, null, [
        new InboundMedia(
            kind: MessageKind::Photo,
            sourceUrl: 'https://minio:9000/fixture-alt.jpg',
            mime: 'image/jpeg',
            bytes: 2048,
        ),
    ]);

    // The slot exists from birth, empty — never absent, so no renderer has to
    // guess whether alt text is "missing" or "unsupported".
    expect($message->media[0])->toHaveKey('alt_text')
        ->and($message->media[0]['alt_text'])->toBeNull();

    $repository = app(MessageRepository::class);
    $repository->setMediaAltText($message, 0, 'Screenshot of a declined withdrawal, showing error code 402');

    expect($message->refresh()->media[0]['alt_text'])
        ->toBe('Screenshot of a declined withdrawal, showing error code 402')
        // The writer touches the alt slot ONLY: scan verdict and storage key are
        // untouched, so an accessibility edit can never reopen SEC-04's absence.
        ->and($message->media[0]['scan_status'])->toBe('pending')
        ->and($message->media[0]['storage_key'])->toBeNull();

    // Clearing it back to null is a legitimate edit, not a deletion.
    $repository->setMediaAltText($message, 0, null);
    expect($message->refresh()->media[0]['alt_text'])->toBeNull();
});
