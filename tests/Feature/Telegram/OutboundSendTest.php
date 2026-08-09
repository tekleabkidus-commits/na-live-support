<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\DeliverableKind;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\OutboundMedia;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SendState;
use App\Domain\Telegram\Outbound\MessageSplitter;
use App\Domain\Telegram\TelegramAdapter;
use Tests\Support\Adapters\ScriptedTelegramApi;

/*
|--------------------------------------------------------------------------
| FR-16/17/19 — outbound send mapping, splitting, typing, delivery truth
|--------------------------------------------------------------------------
*/

function outboundAdapter(ScriptedTelegramApi $api, string $token = '8144038606:FAKECREDkkkkkkkkkkkkkkkkkkkkkkkkkk'): TelegramAdapter
{
    return new TelegramAdapter(
        api: $api,
        capabilities: new Capabilities(
            maxText: 4096, mediaTypes: ['photo', 'voice', 'video', 'audio', 'document', 'sticker'],
            supportsTyping: true, supportsEdit: true, supportsDelete: true, supportsButtons: false,
            replyWindow: ReplyWindow::duration(86_400),
        ),
        botKey: 'out-bot',
        tokenResolver: static fn (array $channelMeta): string => $token,
    );
}

it('FR-16: send maps kind → method and carries business_connection_id on business sends', function (): void {
    $api = new ScriptedTelegramApi;
    $adapter = outboundAdapter($api);

    $adapter->send(new OutboundMessage('chat-1', DeliverablePayload::text('hello'), channelMeta: ['business_connection_id' => 'conn-9']));
    $adapter->send(new OutboundMessage('chat-1', DeliverablePayload::media(
        DeliverableKind::Photo, new OutboundMedia('t/x/p.jpg', 'image/jpeg', 100, 10, 10),
    )));

    expect($api->calls[0]['method'])->toBe('sendMessage')
        ->and($api->calls[0]['params']['business_connection_id'] ?? null)->toBe('conn-9')
        ->and($api->calls[0]['params']['text'])->toBe('hello')
        ->and($api->calls[1]['method'])->toBe('sendPhoto');
});

it('FR-17: terminal success is "Sent" with the channel message id — never a fabricated delivered state', function (): void {
    $api = new ScriptedTelegramApi;
    $result = outboundAdapter($api)->send(new OutboundMessage('chat-1', DeliverablePayload::text('hi')));

    expect($result->state)->toBe(SendState::Sent)->and($result->externalMessageId)->not->toBeNull();
});

it('FR-19: typing is best-effort — a send-action failure is silently swallowed', function (): void {
    $api = new ScriptedTelegramApi;
    $api->script(App\Domain\Telegram\Api\TelegramTransportError::forMethod('sendChatAction', 'ConnectException', 'refused'));
    $adapter = outboundAdapter($api);

    // Must not throw.
    $adapter->sendTyping('chat-1');
    expect($api->calls)->toHaveCount(1)->and($api->calls[0]['method'])->toBe('sendChatAction');
});

// ── FR-16 splitting + EC-09 resume (sabotage #15) ────────────────────────────

it('FR-16: text over max_text splits at WORD boundaries into part i/n, each within the limit', function (): void {
    $word = str_repeat('lipsum ', 1000); // ~7000 chars, well over 4096
    $parts = MessageSplitter::split(trim($word), 4096);

    expect(count($parts))->toBeGreaterThan(1);
    foreach ($parts as $i => $part) {
        expect(mb_strlen($part['text']))->toBeLessThanOrEqual(4096, "part {$part['index']} within the limit")
            ->and($part['text'])->toContain(sprintf('(part %d/%d)', $part['index'], $part['total']))
            ->and($part['index'])->toBe($i + 1);
        // Word boundary: no part starts or ends mid-"lipsum".
        $content = trim(str_replace(sprintf("\n\n(part %d/%d)", $part['index'], $part['total']), '', $part['text']));
        expect($content)->not->toStartWith('psum')->and($content)->not->toEndWith('lip');
    }
});

it('FR-16: a single word longer than the budget is hard-split, never dropped', function (): void {
    $giant = str_repeat('A', 9000);
    $parts = MessageSplitter::split($giant, 4096);

    $reassembled = implode('', array_map(
        fn (array $p): string => preg_replace('/\n\n\(part \d+\/\d+\)$/', '', $p['text']) ?? '',
        $parts,
    ));
    expect(mb_strlen($reassembled))->toBe(9000, 'no character is dropped in a hard split');
});

it('EC-09 (sabotage #15): a resume recomputes the SAME segments and picks up at the failed part — never restarts at 1', function (): void {
    // The lane holds mid-split; resume = re-split the SAME text and send from
    // the failed index. The splitter is deterministic, so parts[failedIndex-1]
    // is byte-identical on resume.
    $text = str_repeat('boundary word here ', 500);
    $first = MessageSplitter::split(trim($text), 4096);
    $resume = MessageSplitter::split(trim($text), 4096);

    expect($resume)->toBe($first, 'the split is deterministic — resume reproduces the exact part list');

    // Simulate: part 2 of N failed; the lane resumes from index 2.
    $failedIndex = 2;
    $partsToSend = array_slice($resume, $failedIndex - 1);
    expect($partsToSend[0]['index'])->toBe($failedIndex, 'EC-09: resume sends the FAILED part, not part 1');
    expect($partsToSend[0]['text'])->toBe($first[$failedIndex - 1]['text'], 'byte-identical to the original failed part');
});
