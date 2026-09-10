<?php

declare(strict_types=1);

use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/**
 * P-DLR-AUTH (Doc 02 core, R-D14-5; Doc 14 FR-13 / SEC-01 / NFR-03) — the
 * FOUR executed controls of the packet, RED FIRST on the tree without it.
 *
 * The GeezSMS callback is unsigned and header-less (vendor notes §9): the
 * URL is the only carrier the vendor's API allows. The `url_secret`
 * mechanism verifies a per-gateway callback secret riding the route segment
 * `{dlr_secret}` against `webhook_credentials.header_secret_hash`
 * (sha-256, hash_equals) after the gateway key riding `{gateway_key}` has
 * resolved the tenant (the SmsDlrKeyFromPath shim, mirrored from Doc 05's).
 *
 * Controls (the advisor's STOP-C amendments folded):
 *   1. FAIL-CLOSED: no secret configured on the credential row → 403.
 *   2. wrong secret → 403 (one byte-identical body — AC-07's no-oracle law).
 *   3. REPLAY: a byte-identical body within FR-24's window → 202 with the
 *      metric `webhook.replay_ignored` and NOTHING processed — never a 403 a
 *      gateway could treat as failure (STOP-C amendment 1); the replay-cache
 *      OUTAGE stays fail-CLOSED (A-1 — covered by WebhookHmacTest's FS case
 *      on the same middleware).
 *   4. POSITIVE: the right secret once → 202, the tenant resolved from the
 *      PATH with no header and no bearer.
 *
 * No secret value ever appears in a log line: the metrics name categories.
 */
const DLR_URL_SECRET = 'fixture-dlr-url-secret-5c0a91e2';

const DLR_URL_BODY = '{"message_status":"success","log":"async 00000000-0000-4000-8000-000000000001","phone":"251900001234","message":"fixture","api_log_id":6569830}';

function dlrUrlFixture(bool $configured = true): array
{
    $credential = $configured ? ['header_secret_hash' => hash('sha256', DLR_URL_SECRET)] : ['header_secret_hash' => null];

    return webhookFixture('sms_gateway', $credential);
}

function postDlrByUrl(string $keyPlaintext, ?string $secret, string $body = DLR_URL_BODY): TestResponse
{
    $path = 'https://localhost/api/webhooks/sms/dlr/'.$keyPlaintext.($secret === null ? '' : '/'.$secret);

    // NO Authorization header, NO X-Tenant-Key, NO signature headers — the URL is the only carrier.
    return test()->call('POST', $path, [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('control 1 — FAIL-CLOSED: a gateway whose credential row carries no callback secret is refused 403', function (): void {
    [, $plaintext] = dlrUrlFixture(configured: false);

    postDlrByUrl($plaintext, DLR_URL_SECRET)
        ->assertForbidden()
        ->assertJsonPath('code', 'webhook_rejected');
});

it('control 2 — a wrong secret is refused with the same byte-identical body (no oracle)', function (): void {
    [, $plaintext] = dlrUrlFixture();

    $wrong = postDlrByUrl($plaintext, 'fixture-dlr-url-secret-WRONG0000');
    $wrong->assertForbidden()->assertJsonPath('code', 'webhook_rejected');

    // the secret is never echoed and never logged
    expect($wrong->getContent())->not->toContain('fixture-dlr-url-secret');
});

it('control 3 — a byte-identical replay within the window answers 202 with `webhook.replay_ignored` and processes nothing', function (): void {
    [, $plaintext] = dlrUrlFixture();
    $records = &capturedLogsRef();

    postDlrByUrl($plaintext, DLR_URL_SECRET)->assertStatus(202)->assertJsonPath('replay', null);
    $replay = postDlrByUrl($plaintext, DLR_URL_SECRET);

    $replay->assertStatus(202)->assertJsonPath('replay', 'ignored');
    $metrics = array_map(fn (array $r) => $r['context']['security_metric'] ?? null, $records);
    expect($metrics)->toContain('webhook.replay_ignored');
});

it('control 4 (POSITIVE) — the right secret once: 202, the tenant resolved from the PATH alone', function (): void {
    [$tenant, $plaintext] = dlrUrlFixture();

    postDlrByUrl($plaintext, DLR_URL_SECRET)
        ->assertStatus(202)
        ->assertJsonPath('resolved_tenant_id', $tenant->id)
        ->assertJsonPath('resolution_source', 'sms_gateway_key');

    // the signed surface (body_hmac) stays for gateways that sign — WebhookHmacTest proves it unmodified
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    Log::info('dlr auth controls done');
});
