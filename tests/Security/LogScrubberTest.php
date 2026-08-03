<?php

declare(strict_types=1);

use App\Support\Logging\ScrubSecrets;
use App\Support\Logging\SecurityLogTap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * SEC-08 + AC-08's log sink (Doc 02 §11, §18; step-12 rulings).
 *
 * Both SEC-08 clauses land here: every rendered record carries trace_id
 * (with tenant_id, C-14), and no password, code, token or secret reaches the
 * rendered sink. The sink under test is the REAL `single` channel — tap,
 * processors, formatter — with only its file path repointed per test so the
 * scan is hermetic.
 *
 * THE COUNTING BAR (owner ruling): every absence assertion first proves its
 * scanner on a planted positive control — plant, detect, remove, then prove
 * absence. A scanner never shown finding a planted sentinel proves nothing.
 *
 * Division of labour (approved interpretation 2): the runtime processor
 * redacts by key/header shape; the guarantee AC-08 counts is the seeded
 * sweep at the bottom of this file. Sabotage (a) — a seeded password leaked
 * under an innocent key — must turn that sweep red, and did (see the S16
 * report's sabotage ledger).
 */

/**
 * Repoint the real `single` channel at a fresh scratch file and make it the
 * default sink for the current test. Everything else about the channel —
 * the tap, the processors, the formatter — is the production configuration.
 */
function scrubSinkFile(): string
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

function sinkContains(string $path, string $needle, bool $digitBoundary = false): bool
{
    $content = file_exists($path) ? (string) file_get_contents($path) : '';

    if ($digitBoundary) {
        // Approved interpretation 3: a 6-digit TOTP code is scanned with
        // digit boundaries so an incidental timestamp digit-run cannot
        // false-positive. Pre-authorized fallback if this ever flakes: pin
        // the challenge path's captured records instead, honestly labeled.
        return preg_match('/(?<![0-9])'.preg_quote($needle, '/').'(?![0-9])/', $content) === 1;
    }

    return str_contains($content, $needle);
}

it('SEC-08: every configured channel carries SecurityLogTap — null and emergency exempt by ruling', function (): void {
    $channels = config('logging.channels');
    $pinned = 0;

    foreach ($channels as $name => $channel) {
        if (in_array($name, ['null', 'emergency'], true)) {
            continue; // `null` discards records; `emergency` is Laravel's fixed-config fallback.
        }

        expect(in_array(SecurityLogTap::class, $channel['tap'] ?? [], true))->toBeTrue(
            sprintf('Channel "%s" ships without the SEC-08 tap — a new channel must carry it, not silently skip it.', $name),
        );
        $pinned++;
    }

    // The exemption list is safe only while this loop is non-vacuous.
    expect($pinned)->toBeGreaterThanOrEqual(8);
});

it('scrubs secret-shaped keys on the rendered sink and passes benign context through', function (): void {
    $path = scrubSinkFile();

    Log::info('scrub liveness probe', [
        'password' => 'FAKECRED-liveness-value-1',
        'reason' => 'benign-reason-survives',
    ]);

    // Detector liveness first: the write reached THIS file at all.
    expect(sinkContains($path, 'scrub liveness probe'))->toBeTrue('the probe never reached the sink — the scan below would be vacuous');
    expect(sinkContains($path, 'benign-reason-survives'))->toBeTrue('benign context was eaten — over-scrubbing breaks the logs SEC-08 wants kept useful');
    expect(sinkContains($path, ScrubSecrets::REDACTED))->toBeTrue();
    expect(sinkContains($path, 'FAKECRED-liveness-value-1'))->toBeFalse('a password-keyed value reached the rendered sink');
});

it('scrubs the webhook auth headers in nested access-log shapes (step-11 inheritance)', function (): void {
    $path = scrubSinkFile();

    Log::info('access-log probe', [
        'request' => [
            'headers' => [
                'X-Telegram-Bot-Api-Secret-Token' => 'FAKECRED-header-telegram-9a',
                'X-Webhook-Signature' => 'FAKECRED-header-signature-9b',
                'Authorization' => 'Bearer FAKECRED-header-bearer-9c',
                'Cookie' => 'nlw_visitor=FAKECRED-header-cookie-9d',
                'Accept-Language' => 'am-ET',
            ],
        ],
    ]);

    expect(sinkContains($path, 'access-log probe'))->toBeTrue();
    expect(sinkContains($path, 'am-ET'))->toBeTrue('benign headers must survive — the access-log shape stays diagnosable');
    expect(sinkContains($path, 'X-Telegram-Bot-Api-Secret-Token'))->toBeTrue('the header NAME should remain visible; only the value is secret');

    foreach (['FAKECRED-header-telegram-9a', 'FAKECRED-header-signature-9b', 'FAKECRED-header-bearer-9c', 'FAKECRED-header-cookie-9d'] as $value) {
        expect(sinkContains($path, $value))->toBeFalse(sprintf('header credential %s reached the rendered sink', $value));
    }
});

it('never scrubs identifier suffixes — token_id and key_id stay traceable', function (): void {
    $path = scrubSinkFile();

    Log::info('identifier probe', [
        'token_id' => '01890000-0000-7000-8000-00000000aaaa',
        'key_id' => '01890000-0000-7000-8000-00000000bbbb',
        'attempt_count' => 3,
    ]);

    expect(sinkContains($path, '01890000-0000-7000-8000-00000000aaaa'))->toBeTrue();
    expect(sinkContains($path, '01890000-0000-7000-8000-00000000bbbb'))->toBeTrue();
});

it('SEC-08/C-14: every rendered record carries trace_id and tenant_id', function (): void {
    $path = scrubSinkFile();

    $tenant = createTenant();
    $traceId = app(TenantContext::class)->correlationId();

    Log::info('trace probe');

    expect(sinkContains($path, 'trace probe'))->toBeTrue();
    expect(sinkContains($path, $traceId))->toBeTrue('the record shipped without the bound correlation id (SEC-08 clause 1)');
    expect(sinkContains($path, (string) $tenant->getKey()))->toBeTrue('the record shipped without tenant_id (C-14)');
});

it('a real refused request logs WITH a trace_id and WITHOUT the presented secret', function (): void {
    $path = scrubSinkFile();

    [, $telegramKey] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', SEEDED_TELEGRAM_HEADER_SECRET),
    ]);

    test()->postJson('https://localhost/api/webhooks/telegram', ['ping' => 'seeded'], [
        'Authorization' => 'Bearer '.$telegramKey,
        'X-Telegram-Bot-Api-Secret-Token' => SEEDED_WRONG_TELEGRAM_SECRET,
    ])->assertStatus(403);

    $content = (string) file_get_contents($path);

    expect($content)->toContain('webhook rejected');
    expect(preg_match('/webhook rejected.*"trace_id":"[0-9a-f-]{36}"/', $content))->toBe(
        1,
        'the refusal line carries no trace_id — SEC-08 clause 1 on a real request path',
    );
    expect(sinkContains($path, SEEDED_WRONG_TELEGRAM_SECRET))->toBeFalse();
    expect(sinkContains($path, $telegramKey))->toBeFalse();
});

it('AC-08: seeded credentials from every real flow are absent from the rendered log sink', function (): void {
    $path = scrubSinkFile();

    $minted = driveSeededCredentialFlows(mailOnDatabaseQueue: false);
    $sentinels = $minted['sentinels'];

    // Roster completeness guard: a shrunken driver must fail loudly, not
    // pass a smaller sweep. 21 = the credential classes Doc 02 minted.
    expect(count($sentinels))->toBeGreaterThanOrEqual(21);

    // The sink saw real traffic — the refusals above guarantee lines exist.
    $flowContent = (string) file_get_contents($path);
    expect(strlen($flowContent))->toBeGreaterThan(0);

    foreach ($sentinels as $name => $value) {
        expect($value)->not->toBe('');
        $digitBoundary = $name === 'totp_code';

        // Positive control per sentinel: plant → detect → remove → gone.
        file_put_contents($path, $flowContent."PLANTED {$name} {$value}\n");
        expect(sinkContains($path, $value, $digitBoundary))->toBeTrue(
            sprintf('scanner failed its positive control for %s — the absence check below would prove nothing', $name),
        );
        file_put_contents($path, $flowContent);

        expect(sinkContains($path, $value, $digitBoundary))->toBeFalse(
            sprintf('seeded credential "%s" leaked into the rendered log sink (AC-08)', $name),
        );
    }
});
