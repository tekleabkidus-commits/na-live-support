<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Messages\MessageKind;
use App\Domain\Telegram\TelegramAdapter;
use Tests\Support\Adapters\ScriptedTelegramApi;

/*
|--------------------------------------------------------------------------
| AC-01 / FR-11…13: every allowed update type → the exact NormalizedInbound
|--------------------------------------------------------------------------
|
| Driven from fixtures/telegram/updates/allowed-updates.json — one shape per
| FR-01 allowed type plus every FR-13 media kind. Media items must carry the
| OPAQUE A-26 reference (T-07: the tokened URL never exists at normalize
| time). EC-11's real-capture fixture integrity is pinned here too, citing
| its evidence path; the consuming upsert test is step 6's.
*/

function mappingAdapter(): TelegramAdapter
{
    return new TelegramAdapter(
        api: new ScriptedTelegramApi,
        capabilities: new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice', 'video', 'audio', 'document', 'sticker'],
            supportsTyping: true, supportsEdit: true, supportsDelete: true, supportsButtons: true,
            replyWindow: ReplyWindow::duration(86_400),
        ),
        botKey: 'fixture-bot-uuid',
        tokenResolver: static fn (): string => '8144038606:FAKECREDgggggggggggggggggggggggggg',
    );
}

/** @return array<string, array<string, mixed>> */
function allowedUpdateFixtures(): array
{
    /** @var array{fixtures: array<string, array<string, mixed>>} $data */
    $data = json_decode((string) file_get_contents(base_path('fixtures/telegram/updates/allowed-updates.json')), true);

    return $data['fixtures'];
}

it('AC-01: message-bearing types map to the exact contract shape, envelope or flat', function (): void {
    $fixtures = allowedUpdateFixtures();
    $adapter = mappingAdapter();

    // Flat (conformance-style) drive.
    $n = $adapter->handleWebhook($fixtures['message']);
    expect($n->kind)->toBe(MessageKind::Text)
        ->and($n->body)->toBe('plain text')
        ->and($n->externalChatId)->toBe('900000100')
        ->and($n->externalMessageId)->toBe('50001')
        ->and($n->sender->externalUserId)->toBe('900000100')
        ->and($n->sender->display)->toBe('Fixture Customer')
        ->and($n->sender->username)->toBe('fixture_customer')
        ->and($n->dedupeKey)->toBe('fixture-bot-uuid:930000001')
        ->and($n->channelMeta['telegram_date'])->toBe(1754700000)
        ->and($n->channelMeta['owner_phone'])->toBeFalse();

    // Envelope (router-style) drive: bot identity from the envelope.
    $e = $adapter->handleWebhook(['bot' => 'env-bot-uuid', 'update' => $fixtures['business_message'], 'owner_phone' => true]);
    expect($e->dedupeKey)->toBe('env-bot-uuid:930000006')
        ->and($e->channelMeta['connection_id'])->toBe('fixture-conn-ec11')
        ->and($e->channelMeta['owner_phone'])->toBeTrue()
        ->and($e->body)->toBe('business text');

    // Edited shapes normalize with the SAME external id (the FR-21 revision
    // path keys on it downstream).
    $edit = $adapter->handleWebhook($fixtures['edited_message']);
    expect($edit->externalMessageId)->toBe('50001')->and($edit->body)->toBe('edited text');
});

it('FR-13: every media kind maps with the OPAQUE A-26 reference — largest photo, mimes, durations; no URL, no token (T-07)', function (): void {
    $fixtures = allowedUpdateFixtures();
    $adapter = mappingAdapter();

    $photo = $adapter->handleWebhook($fixtures['media_photo']);
    expect($photo->kind)->toBe(MessageKind::Photo)
        ->and($photo->body)->toBe('photo caption')
        ->and($photo->media)->toHaveCount(1)
        ->and($photo->media[0]->sourceUrl)->toBe('telegram-file:fixture-bot-uuid/large-1') // the LARGEST size
        ->and($photo->media[0]->width)->toBe(800);

    $voice = $adapter->handleWebhook($fixtures['media_voice']);
    expect($voice->media[0]->kind)->toBe(MessageKind::Voice)
        ->and($voice->media[0]->mime)->toBe('audio/ogg')
        ->and($voice->media[0]->durationSeconds)->toBe(7);

    $video = $adapter->handleWebhook($fixtures['media_video']);
    expect($video->media[0]->kind)->toBe(MessageKind::Video);

    $note = $adapter->handleWebhook($fixtures['media_video_note']);
    expect($note->media[0]->kind)->toBe(MessageKind::Video); // video_note rides Video

    $doc = $adapter->handleWebhook($fixtures['media_document']);
    expect($doc->media[0]->kind)->toBe(MessageKind::Document)
        ->and($doc->media[0]->mime)->toBe('application/pdf')
        ->and($doc->media[0]->bytes)->toBe(17);

    $sticker = $adapter->handleWebhook($fixtures['media_sticker']);
    expect($sticker->media[0]->kind)->toBe(MessageKind::Sticker)
        ->and($sticker->media[0]->mime)->toBe('image/webp');

    // T-07, structurally: NO mapped source carries a URL or token shape.
    foreach (['media_photo', 'media_voice', 'media_video', 'media_document', 'media_sticker', 'media_video_note'] as $key) {
        $mapped = $adapter->handleWebhook($fixtures[$key]);
        foreach ($mapped->media as $item) {
            expect($item->sourceUrl)->toStartWith('telegram-file:')
                ->and(str_contains($item->sourceUrl, 'api.telegram.org'))->toBeFalse()
                ->and(str_contains($item->sourceUrl, 'FAKECRED'))->toBeFalse();
        }
    }
});

it('EC-11 fixture integrity: the three REAL captured updates share ONE connection id; the third carries the widened rights', function (): void {
    /** @var array{_provenance: array{source: string}, updates: list<array{update_id: int, business_connection: array{id: string, rights: array<string, bool>}}>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/telegram/updates/business-connection-real-capture.json')), true);

    expect($fixture['_provenance']['source'])->toBe('docs/evidence/S23-step0/0b-reproof-attempt2.txt');
    expect($fixture['updates'])->toHaveCount(3);

    $ids = array_unique(array_map(fn (array $u): string => $u['business_connection']['id'], $fixture['updates']));
    expect($ids)->toBe(['E9brjRFqwEexAQAAaQdVUkBf9vs'], 'EC-11: all three updates must reference ONE connection');

    expect($fixture['updates'][2]['business_connection']['rights'])->toHaveKey('can_view_gifts_and_stars');
    expect($fixture['updates'][0]['business_connection']['rights'])->not->toHaveKey('can_view_gifts_and_stars');
});
