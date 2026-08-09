<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * FR-23 (secret_header) + FR-25's per-key limit + AC-07 on the telegram
 * ingress probe (Doc 02 §4.7, §18; step-11 rulings).
 *
 * THE POSITIVE CONTROL COMES FIRST, by protocol. The refusal tests then pin
 * AC-07's three clauses separately: 403 (status + code), metric incremented
 * (captured off the log bus), nothing processed (the spy route's handler
 * records its own invocation, and the probe payload is absent from refusal
 * bodies). All refusal causes render byte-identically (trace_id normalized —
 * it varies per request BY DESIGN, C-14): mismatch, absent header and
 * unconfigured key are one indistinguishable 403, per the unified-refusal
 * ruling.
 */
const TELEGRAM_FIXTURE_SECRET = 'fixture-telegram-secret-3f9c2a71';

function telegramWebhook(string $keyPlaintext, ?string $secret): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.$keyPlaintext];

    if ($secret !== null) {
        $headers['X-Telegram-Bot-Api-Secret-Token'] = $secret;
    }

    return test()->postJson('https://localhost/api/webhooks/telegram', ['ping' => 'fixture'], $headers);
}

// capturedLogsRef(), normalizedProblemBody() and webhookFixture() live in
// tests/Pest.php — shared with WebhookHmacTest, and documented there.

it('accepts the probe with the correct secret header (FR-23 — the positive control)', function (): void {
    [$tenant, $plaintext] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
    ]);

    // Doc 05 S23 step 4: the probe's echo body died with the probe — the
    // REAL handler ACKs {'accepted': true} (FR-02) and echoes nothing. The
    // auth mechanics this file proves are unchanged: 202 here vs the
    // byte-identical 403s below.
    telegramWebhook($plaintext, TELEGRAM_FIXTURE_SECRET)
        ->assertStatus(202)
        ->assertExactJson(['accepted' => true]);
});

it('refuses a mismatched secret: 403, nothing processed, metric incremented (AC-07)', function (): void {
    [, $plaintext] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
    ]);

    $records = &capturedLogsRef();

    $response = telegramWebhook($plaintext, 'the-wrong-secret')
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'webhook_rejected');

    // Nothing processed: the probe's payload is what "processing" produces
    // here, and none of it is present.
    expect($response->json('resolved_tenant_id'))->toBeNull();

    // Metric incremented, with the cause monitoring needs and the response
    // withholds.
    $metrics = array_filter($records, fn (array $r): bool => ($r['context']['security_metric'] ?? null) === 'webhook.secret_rejected');
    expect($metrics)->not->toBeEmpty();
    expect(array_column(array_map(fn (array $r) => $r['context'], $metrics), 'reason'))->toContain('secret_mismatch');
});

it('renders mismatch, absent header and unconfigured key byte-identically (the no-oracle ruling)', function (): void {
    [, $withCredential] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
    ]);
    [, $unconfigured] = webhookFixture('telegram_bot', null);

    $mismatch = telegramWebhook($withCredential, 'the-wrong-secret');
    $absent = telegramWebhook($withCredential, null);
    $noRow = telegramWebhook($unconfigured, TELEGRAM_FIXTURE_SECRET);

    foreach ([$mismatch, $absent, $noRow] as $refusal) {
        $refusal->assertForbidden();
    }

    $bodies = array_map(
        fn (TestResponse $r): string => normalizedProblemBody((string) $r->getContent()),
        [$mismatch, $absent, $noRow],
    );

    expect($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0]);
});

it('never invokes the handler on a refused request — proven by a spy on the REAL stack (AC-07 "nothing processed")', function (): void {
    [, $plaintext] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
    ]);

    $spy = new stdClass;
    $spy->invocations = 0;

    Route::middleware([
        'tenant:telegram_bot_key',
        'webhook.throttle',
        'webhook.auth:secret_header,X-Telegram-Bot-Api-Secret-Token',
        'tenant.state:inbound',
    ])->post('api/webhook-test/spy', function () use ($spy) {
        $spy->invocations++;

        return response()->json(['spied' => true], 202);
    });

    $call = function (?string $secret) use ($plaintext): TestResponse {
        $headers = ['Authorization' => 'Bearer '.$plaintext];
        if ($secret !== null) {
            $headers['X-Telegram-Bot-Api-Secret-Token'] = $secret;
        }

        return test()->postJson('https://localhost/api/webhook-test/spy', [], $headers);
    };

    // The spy itself is proven live first — a spy that cannot record its own
    // invocation would make the zero below vacuous.
    $call(TELEGRAM_FIXTURE_SECRET)->assertStatus(202)->assertJsonPath('spied', true);
    expect($spy->invocations)->toBe(1);

    $call('the-wrong-secret')->assertForbidden();
    $call(null)->assertForbidden();

    expect($spy->invocations)->toBe(1, 'a refused webhook request reached the handler — AC-07\'s "nothing processed" is broken');
});

it('rate-limits per KEY: one flooding bot never starves its sibling (FR-25)', function (): void {
    // Two telegram keys in ONE tenant — the isolation FR-25 implies and the
    // sabotage flips: keyed per tenant, B's cell below goes red.
    [$tenant, $plaintextA, $keyIdA] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
    ]);

    [$plaintextB] = asTenant($tenant, function (): array {
        $issued = app(App\Support\Tenancy\TenantKeyIssuer::class)->issue(
            kind: 'telegram_bot',
            label: 'fixture sibling bot',
            actor: App\Support\Audit\AuditActor::PlatformAdmin,
        );
        App\Models\WebhookCredential::query()->create([
            'key_id' => $issued['key']->getKey(),
            'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
        ]);

        // Doc 05 S23 step 4: the real handler resolves the bot behind the
        // key — hand-issued keys carry the row the wizard writes.
        App\Models\TelegramBot::query()->create([
            'telegram_bot_id' => random_int(1_000_000_000, 9_999_999_999),
            'username' => 'fixture_ratelimit_bot',
            'display_name' => 'Fixture Ratelimit Bot',
            'mode' => 'direct',
            'key_id' => $issued['key']->getKey(),
        ]);

        return [$issued['plaintext']];
    });

    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();

    // Positive control before exhaustion: key A works.
    telegramWebhook($plaintextA, TELEGRAM_FIXTURE_SECRET)->assertStatus(202);

    // Exhaust key A's bucket at the Redis layer (registered default 600/min;
    // driving 600 real HTTP requests would prove nothing extra and cost a
    // minute of suite time — the limiter's own counter is the mechanism
    // under test either way).
    $limiter = app(RateLimiter::class);
    for ($i = 0; $i < 600; $i++) {
        $limiter->hit('webhook-key:'.$keyIdA, 60);
    }

    telegramWebhook($plaintextA, TELEGRAM_FIXTURE_SECRET)
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');

    // The sibling key, same tenant, same moment: untouched.
    telegramWebhook($plaintextB, TELEGRAM_FIXTURE_SECRET)->assertStatus(202);
});

it('establishes no session and sets no cookie on any outcome (FR-25 structural)', function (): void {
    [, $plaintext] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', TELEGRAM_FIXTURE_SECRET),
    ]);

    $accepted = telegramWebhook($plaintext, TELEGRAM_FIXTURE_SECRET);
    $refused = telegramWebhook($plaintext, 'the-wrong-secret');

    expect($accepted->baseResponse->headers->getCookies())->toBeEmpty()
        ->and($refused->baseResponse->headers->getCookies())->toBeEmpty();
    // The executed-stack ABSENCE of session middleware is MiddlewareOrderTest's;
    // this asserts the observable half on real responses.
});
