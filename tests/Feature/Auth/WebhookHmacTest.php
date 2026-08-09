<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * FR-24 (body_hmac + the ≤ 5 min replay window) on the DLR ingress probe
 * (Doc 02 §4.7, AC-07; step-11 rulings 1 and 3).
 *
 * THE SIGNING CONTRACT UNDER TEST: X-Webhook-Signature =
 * HMAC-SHA256("{timestamp}.{nonce}.{raw body}", secret). Coverage of the
 * timestamp AND nonce is itself asserted below — an HMAC over the body alone
 * would let a captured body replay forever behind fresh nonces, making the
 * window fiction (the middleware docblock carries the argument; the
 * new-nonce-old-signature test is its proof).
 *
 * Clock discipline: boundaries use Carbon::setTestNow — the suite never
 * sleeps and never asserts on wall-clock now() (CLAUDE.md §8).
 */
const DLR_FIXTURE_SECRET = 'fixture-dlr-hmac-secret-8b1d44c0';

const DLR_FIXTURE_BODY = '{"message_id":"fixture-dlr-1","status":"delivered"}';

/**
 * A signed DLR request with any part overridable for tampering.
 *
 * @param  array{timestamp?: string, nonce?: string, signature?: string, body?: string, secret?: string}  $overrides
 */
function signedDlr(string $keyPlaintext, array $overrides = []): TestResponse
{
    $body = $overrides['body'] ?? DLR_FIXTURE_BODY;
    $timestamp = $overrides['timestamp'] ?? (string) CarbonImmutable::now()->getTimestamp();
    $nonce = $overrides['nonce'] ?? 'fixture-nonce-'.bin2hex(random_bytes(8));
    $signature = $overrides['signature'] ?? hash_hmac(
        'sha256',
        sprintf('%s.%s.%s', $timestamp, $nonce, $body),
        $overrides['secret'] ?? DLR_FIXTURE_SECRET,
    );

    return test()->call('POST', 'https://localhost/api/webhooks/sms/dlr', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$keyPlaintext,
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_X_WEBHOOK_NONCE' => $nonce,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function dlrFixture(): array
{
    return webhookFixture('sms_gateway', ['hmac_secret' => DLR_FIXTURE_SECRET]);
}

it('accepts a correctly signed DLR (FR-24 — the positive control)', function (): void {
    [$tenant, $plaintext] = dlrFixture();

    signedDlr($plaintext)
        ->assertStatus(202)
        ->assertJsonPath('resolved_tenant_id', $tenant->id)
        ->assertJsonPath('resolution_source', 'sms_gateway_key');
});

it('refuses a wrong signature and a tampered body alike (FR-24)', function (): void {
    [, $plaintext] = dlrFixture();

    // Garbage signature.
    signedDlr($plaintext, ['signature' => str_repeat('ab', 32)])
        ->assertForbidden()
        ->assertJsonPath('code', 'webhook_rejected');

    // A VALID signature over a DIFFERENT body — the tamper case: sign the
    // fixture body, deliver another.
    $timestamp = (string) CarbonImmutable::now()->getTimestamp();
    $nonce = 'fixture-nonce-'.bin2hex(random_bytes(8));
    $signatureOverFixture = hash_hmac(
        'sha256',
        sprintf('%s.%s.%s', $timestamp, $nonce, DLR_FIXTURE_BODY),
        DLR_FIXTURE_SECRET,
    );

    signedDlr($plaintext, [
        'timestamp' => $timestamp,
        'nonce' => $nonce,
        'signature' => $signatureOverFixture,
        'body' => '{"message_id":"fixture-dlr-1","status":"failed"}',
    ])->assertForbidden();
});

it('refuses a captured signature behind a fresh nonce — the HMAC covers timestamp and nonce, not just the body', function (): void {
    [, $plaintext] = dlrFixture();

    $timestamp = (string) CarbonImmutable::now()->getTimestamp();
    $capturedNonce = 'fixture-nonce-captured';
    $capturedSignature = hash_hmac(
        'sha256',
        sprintf('%s.%s.%s', $timestamp, $capturedNonce, DLR_FIXTURE_BODY),
        DLR_FIXTURE_SECRET,
    );

    // The original goes through…
    signedDlr($plaintext, [
        'timestamp' => $timestamp,
        'nonce' => $capturedNonce,
        'signature' => $capturedSignature,
    ])->assertStatus(202);

    // …and the attacker who captured it cannot re-spend it behind a fresh
    // nonce: the signature stops matching, which is exactly the property
    // that makes the replay window real rather than decorative.
    signedDlr($plaintext, [
        'timestamp' => $timestamp,
        'nonce' => 'fixture-nonce-minted-by-attacker',
        'signature' => $capturedSignature,
    ])->assertForbidden();
});

it('enforces the ±300 s window at its exact boundaries, with an injected clock (FR-24)', function (): void {
    [, $plaintext] = dlrFixture();

    $frozen = CarbonImmutable::parse('2026-08-03 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($frozen);

    try {
        // 301 s stale and 301 s ahead: refused.
        signedDlr($plaintext, ['timestamp' => (string) $frozen->subSeconds(301)->getTimestamp()])
            ->assertForbidden();
        signedDlr($plaintext, ['timestamp' => (string) $frozen->addSeconds(301)->getTimestamp()])
            ->assertForbidden();

        // 299 s stale: still inside the window.
        signedDlr($plaintext, ['timestamp' => (string) $frozen->subSeconds(299)->getTimestamp()])
            ->assertStatus(202);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('spends a nonce exactly once: the identical request replayed is refused with the metric (FR-24, AC-07)', function (): void {
    [, $plaintext] = dlrFixture();

    $records = &capturedLogsRef();

    $fixed = [
        'timestamp' => (string) CarbonImmutable::now()->getTimestamp(),
        'nonce' => 'fixture-nonce-replayed-once',
    ];

    signedDlr($plaintext, $fixed)->assertStatus(202);
    signedDlr($plaintext, $fixed)
        ->assertForbidden()
        ->assertJsonPath('code', 'webhook_rejected');

    $reasons = array_map(
        fn (array $r) => $r['context']['reason'] ?? null,
        array_filter($records, fn (array $r): bool => ($r['context']['security_metric'] ?? null) === 'webhook.secret_rejected'),
    );
    expect(array_values($reasons))->toContain('nonce_replayed');
});

it('never lets an unsigned caller burn a nonce out of the cache (spend-after-verify)', function (): void {
    [, $plaintext] = dlrFixture();

    $nonce = 'fixture-nonce-burn-attempt';

    // An attacker without the secret presents the nonce first…
    signedDlr($plaintext, ['nonce' => $nonce, 'signature' => str_repeat('cd', 32)])
        ->assertForbidden();

    // …and the legitimate gateway's later use of the SAME nonce still works:
    // the cache only ever records nonces whose signatures verified.
    signedDlr($plaintext, ['nonce' => $nonce])->assertStatus(202);
});

it('refuses unconfigured keys and absent headers with the same byte-identical body (no-oracle ruling)', function (): void {
    [, $configured] = dlrFixture();
    [, $unconfigured] = webhookFixture('sms_gateway', null);

    $badSignature = signedDlr($configured, ['signature' => str_repeat('ef', 32)]);
    $noHeaders = test()->call('POST', 'https://localhost/api/webhooks/sms/dlr', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$configured,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], DLR_FIXTURE_BODY);
    $noRow = signedDlr($unconfigured);

    foreach ([$badSignature, $noHeaders, $noRow] as $refusal) {
        expect($refusal->getStatusCode())->toBe(403);
    }

    $bodies = array_map(
        fn ($r): string => normalizedProblemBody((string) $r->getContent()),
        [$badSignature, $noHeaders, $noRow],
    );

    expect($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0]);
});

it('fails CLOSED on replay-cache outage while the Redis-free surface stays up (FS posture, rulings 1 and 3)', function (): void {
    [, $dlrPlaintext] = dlrFixture();
    [$telegramTenant, $telegramPlaintext] = webhookFixture('telegram_bot', [
        'header_secret_hash' => hash('sha256', 'fixture-telegram-outage-secret'),
    ]);

    $records = &capturedLogsRef();

    // A real connection failure, not a mock (the QuotaTest FS-01 posture).
    config([
        'database.redis.volatile.host' => '127.0.0.1',
        'database.redis.volatile.port' => 1,
    ]);
    app('redis')->purge('volatile');

    try {
        // The HMAC surface: signature VALID, replay unverifiable → refused,
        // with the named metric — never waved through (filed interpretation,
        // ruling decision 3).
        signedDlr($dlrPlaintext)
            ->assertForbidden()
            ->assertJsonPath('code', 'webhook_rejected');

        $metrics = array_map(
            fn (array $r) => $r['context']['security_metric'] ?? null,
            $records,
        );
        expect($metrics)->toContain('webhook.replay_cache_unavailable');

        // The secret_header surface on the SAME broken Redis: still 202.
        // Three fail-open seams carry it, each with a named metric: the
        // resolver's keymap cache reads as a miss (C-07 — cache is never
        // the source of truth), the per-key throttle counts nothing, and
        // the secret comparison itself never needed Redis. FS-01's
        // "webhooks unaffected" stays true where its rationale applies.
        test()->postJson('https://localhost/api/webhooks/telegram', ['ping' => 'fixture'], [
            'Authorization' => 'Bearer '.$telegramPlaintext,
            'X-Telegram-Bot-Api-Secret-Token' => 'fixture-telegram-outage-secret',
        ])
            ->assertStatus(202)
            // Doc 05 S23 step 4: the real handler's ACK body (the probe echo died).
            ->assertExactJson(['accepted' => true]);

        expect($metrics = array_map(
            fn (array $r) => $r['context']['security_metric'] ?? null,
            $records,
        ))->toContain('webhook.throttle_unavailable');
    } finally {
        // The suite's other tests need the real instance back.
        config([
            'database.redis.volatile.host' => env('REDIS_VOLATILE_HOST', 'redis-volatile'),
            'database.redis.volatile.port' => env('REDIS_VOLATILE_PORT', '6379'),
        ]);
        app('redis')->purge('volatile');
    }
});

it('nonce single-use survives the window boundary honestly: expiry is Redis TTL, not test sleep', function (): void {
    // The 5-minute nonce TTL cannot be time-traveled (Redis expiry is real
    // seconds), so the boundary is proven the widget-TTL way: shrink the
    // key's TTL at the Redis layer and observe re-admission — which is the
    // DESIGNED behavior (a nonce outside the window is only re-admissible
    // when its timestamp check would refuse the replay anyway; that
    // independence is the sabotage-(d) property).
    [$tenant, $plaintext] = dlrFixture();

    $nonce = 'fixture-nonce-ttl-boundary';
    signedDlr($plaintext, ['nonce' => $nonce])->assertStatus(202);

    // Force the nonce record to lapse as if 5 minutes passed.
    $redisKey = sprintf('t:%s:webhook-nonce:%s', $tenant->id, hash('sha256', $nonce));
    app(Illuminate\Contracts\Redis\Factory::class)->connection('volatile')->command('del', [$redisKey]);

    // Same nonce, fresh timestamp/signature: admitted again — the nonce
    // cache promises single-use WITHIN the window, and the timestamp check
    // owns everything beyond it.
    signedDlr($plaintext, ['nonce' => $nonce])->assertStatus(202);
});
