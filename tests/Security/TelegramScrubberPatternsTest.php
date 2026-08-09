<?php

declare(strict_types=1);

use App\Support\Logging\ScrubSecrets;
use Illuminate\Support\Facades\Log;

/**
 * Doc 05 NFR-06 (ruling A-19): zero Telegram tokens or file URLs in logs.
 *
 * The SEC-08 scrubber gained two VALUE-shape patterns — the bot-token format
 * and the api.telegram.org file-URL format (T-07: file URLs CONTAIN the
 * token). Key-shape scrubbing cannot catch a token under an innocent key
 * (`url`, `detail`) or inside the message string; these patterns must.
 *
 * THE COUNTING BAR (S19 #7): every absence assertion first proves its scanner
 * on a planted positive control. Here the control is a NEAR-MISS value that
 * deliberately fails the pattern by one property (token suffix shorter than
 * the 30-char floor) — it must SURVIVE to the sink, proving the write path,
 * the scanner, and the exact pattern boundary all at once. Only then do the
 * real-shaped fakes get asserted absent.
 *
 * All values are synthetic (FAKECRED / fixture discipline) — no real token
 * ever appears in this file or its sink.
 */

// Same sink mechanics as LogScrubberTest (the real `single` channel with only
// its path repointed); distinct names so both files stay standalone with no
// load-order coupling.
function telegramScrubSink(): string
{
    $path = sys_get_temp_dir().'/scrub-sink-'.bin2hex(random_bytes(8)).'.log';

    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single'],
        'logging.channels.single.path' => $path,
    ]);

    Log::forgetChannel('stack');
    Log::forgetChannel('single');

    return $path;
}

function telegramSinkContains(string $path, string $needle): bool
{
    $content = file_exists($path) ? (string) file_get_contents($path) : '';

    return str_contains($content, $needle);
}

// Real BotFather shape: digits, colon, ≥30 base64url chars (34 here).
const FAKE_BOT_TOKEN = '8144038606:FAKECREDaaaaaaaaaaaaaaaaaaaaaaaaaa';
// One property off: only 12 chars after the colon — outside the pattern.
const NEAR_MISS_TOKEN = '8144038606:FAKECREDshrt';
const FAKE_FILE_URL = 'https://api.telegram.org/file/bot'.FAKE_BOT_TOKEN.'/photos/file_0.jpg';

it('NFR-06: a bot-token-shaped VALUE under an innocent key never reaches the sink; the near-miss control survives', function (): void {
    $path = telegramScrubSink();

    Log::info('telegram token value probe', [
        'detail' => 'send failed for '.FAKE_BOT_TOKEN.' at attempt 2',
        'near_miss' => NEAR_MISS_TOKEN,
        'reason' => 'benign-reason-survives',
    ]);

    expect(telegramSinkContains($path, 'telegram token value probe'))->toBeTrue('the probe never reached the sink — the scan below would be vacuous');
    // Positive control: the near-miss proves the scanner AND the boundary.
    expect(telegramSinkContains($path, NEAR_MISS_TOKEN))->toBeTrue('the near-miss control was eaten — the pattern is wider than filed, or the scanner is dead');
    expect(telegramSinkContains($path, 'benign-reason-survives'))->toBeTrue('benign context was eaten — over-scrubbing breaks the logs SEC-08 wants kept useful');
    expect(telegramSinkContains($path, ScrubSecrets::REDACTED))->toBeTrue();
    expect(telegramSinkContains($path, FAKE_BOT_TOKEN))->toBeFalse('a bot-token-shaped value reached the rendered sink (NFR-06)');
});

it('NFR-06: a token inside the MESSAGE string is scrubbed — free text is a log too', function (): void {
    $path = telegramScrubSink();

    Log::warning('getFile failed for '.FAKE_BOT_TOKEN.' — retrying');

    expect(telegramSinkContains($path, 'getFile failed'))->toBeTrue('the probe never reached the sink');
    expect(telegramSinkContains($path, 'retrying'))->toBeTrue('message tail was eaten — the pattern must replace only the token');
    expect(telegramSinkContains($path, FAKE_BOT_TOKEN))->toBeFalse('a bot token in the message string reached the rendered sink (NFR-06)');
});

it('T-07: a Telegram file URL is scrubbed WHOLE — no api.telegram.org/file fragment survives; benign URLs pass', function (): void {
    $path = telegramScrubSink();

    Log::info('media fetch probe', [
        'url' => FAKE_FILE_URL,
        'benign_url' => 'https://example.com/keep-me-visible',
    ]);

    expect(telegramSinkContains($path, 'media fetch probe'))->toBeTrue('the probe never reached the sink');
    expect(telegramSinkContains($path, 'https://example.com/keep-me-visible'))->toBeTrue('a benign URL was eaten — the file-URL pattern must stay host-anchored');
    expect(telegramSinkContains($path, FAKE_BOT_TOKEN))->toBeFalse('the token inside the file URL reached the sink (T-07)');
    expect(telegramSinkContains($path, 'api.telegram.org/file'))->toBeFalse('a file-URL fragment survived — the URL must be scrubbed whole');
});
