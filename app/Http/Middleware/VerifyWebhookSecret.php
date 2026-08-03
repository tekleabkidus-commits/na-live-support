<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Webhooks\ReplayGuard;
use App\Domain\Auth\Webhooks\WebhookRejected;
use App\Models\WebhookCredential;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `webhook.auth` — the second credential on the inbound webhook surfaces
 * (Doc 02 FR-23, FR-24, AC-07; step-11 rulings).
 *
 * Two MECHANISMS, named for what they verify, never for a channel (N-20 —
 * the route file binds a mechanism and a header name to a surface; nothing
 * here knows Telegram from an SMS gateway):
 *
 *   secret_header   FR-23's shape. A bearer-style secret in a named header,
 *                   compared as SHA-256 digests via hash_equals. Touches no
 *                   Redis — FS-01's "webhooks unaffected" holds literally
 *                   for this mechanism.
 *   body_hmac       FR-24's shape. X-Webhook-Signature = hex
 *                   HMAC-SHA256("{timestamp}.{nonce}.{raw body}", secret),
 *                   X-Webhook-Timestamp within ±300 s, X-Webhook-Nonce
 *                   single-use (ReplayGuard). Doc 14's drivers adapt each
 *                   gateway's native scheme onto this contract.
 *
 * THE SIGNATURE COVERS TIMESTAMP AND NONCE, not the body alone, and that is
 * load-bearing: an HMAC over only the body would let an attacker replay a
 * captured body forever by minting fresh timestamps and nonces — the replay
 * window would be fiction. FR-24's "or the gateway's native signature scheme
 * per driver" grants the latitude; native schemes (Stripe/Twilio family)
 * sign the timestamp for exactly this reason.
 *
 * CHECK ORDER, cheapest first, refusals unified: configured row → header
 * presence → timestamp window → signature → nonce first-use. The nonce is
 * spent ONLY after the signature verifies, so an attacker without the secret
 * cannot burn a legitimate gateway's nonces out of the cache.
 *
 * CONSTANT-TIME POSTURE (ruling, decision 2): every comparison is
 * hash_equals, pinned by WebhookComparatorPinTest — an architecture pin,
 * honestly labeled: nanosecond memcmp timing is below measurement noise, so
 * the pin asserts the source, and the always-true sabotage proves the
 * comparison is load-bearing at all.
 *
 * Runs AFTER `tenant:*` (needs the bound RLS scope and the resolved key id)
 * and BEFORE the controller — AC-07's "nothing processed" is structural:
 * this throws from middleware, the handler is never invoked, and the
 * spy-route test asserts that positively. Nothing on this stack is
 * priority-listed, so declared order is executed order (the step-10 lesson).
 */
final class VerifyWebhookSecret
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';

    public const NONCE_HEADER = 'X-Webhook-Nonce';

    public function __construct(private readonly ReplayGuard $replayGuard) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $mechanism, string $headerName = ''): Response
    {
        $credential = $this->credentialFor($request);

        match ($mechanism) {
            'secret_header' => $this->verifySecretHeader($request, $credential, $headerName),
            'body_hmac' => $this->verifyBodyHmac($request, $credential),
            default => throw new \LogicException(sprintf(
                'Unknown webhook auth mechanism "%s". The set is secret_header|body_hmac; adding one is a Doc 02 change, not a route parameter.',
                $mechanism,
            )),
        };

        return $next($request);
    }

    private function verifySecretHeader(Request $request, ?WebhookCredential $credential, string $headerName): void
    {
        if ($headerName === '') {
            throw new \LogicException('secret_header requires the header name as its second middleware parameter.');
        }

        $storedHash = $credential?->header_secret_hash;

        if (! is_string($storedHash) || $storedHash === '') {
            throw WebhookRejected::secretRejected('key_unconfigured');
        }

        $presented = $request->header($headerName);

        if (! is_string($presented) || $presented === '') {
            throw WebhookRejected::secretRejected('header_absent');
        }

        if (! hash_equals($storedHash, hash('sha256', $presented))) {
            throw WebhookRejected::secretRejected('secret_mismatch');
        }
    }

    private function verifyBodyHmac(Request $request, ?WebhookCredential $credential): void
    {
        $secret = $credential?->hmac_secret;

        if (! is_string($secret) || $secret === '') {
            throw WebhookRejected::secretRejected('key_unconfigured');
        }

        $signature = $request->header(self::SIGNATURE_HEADER);
        $timestamp = $request->header(self::TIMESTAMP_HEADER);
        $nonce = $request->header(self::NONCE_HEADER);

        if (! is_string($signature) || $signature === ''
            || ! is_string($timestamp) || $timestamp === ''
            || ! is_string($nonce) || $nonce === '') {
            throw WebhookRejected::secretRejected('header_absent');
        }

        // FR-24's window, both directions: a stale capture AND a pre-dated
        // one are refused. NTP keeps the clocks honest (EC-07); the clock is
        // Carbon's so tests inject via setTestNow, never sleep.
        if (preg_match('/^\d{1,20}$/', $timestamp) !== 1
            || abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > ReplayGuard::WINDOW_SECONDS) {
            throw WebhookRejected::secretRejected('timestamp_out_of_window');
        }

        $expected = hash_hmac(
            'sha256',
            sprintf('%s.%s.%s', $timestamp, $nonce, $request->getContent()),
            $secret,
        );

        if (! hash_equals($expected, strtolower($signature))) {
            throw WebhookRejected::secretRejected('signature_mismatch');
        }

        $tenantId = $credential->getAttribute('tenant_id');

        if (! is_string($tenantId)) {
            throw new \LogicException('Webhook credential row carries no tenant id; RLS should make this impossible.');
        }

        try {
            $firstUse = $this->replayGuard->firstUse($tenantId, $nonce);
        } catch (\RedisException $e) {
            // phpredis's connection failure class — the ONLY client this
            // stack runs (config/database.php pins REDIS_CLIENT=phpredis;
            // a Predis alternative isn't installed, so catching its
            // exception class would be dead code referencing a missing
            // symbol).
            // Ruling, decision 3 (filed interpretation): unverifiable-for-
            // replay is REFUSED, never waved through; the gateway's retry
            // carries availability. CONNECTION failures only — a broad
            // Throwable here once converted a phpredis TypeError into a
            // fake outage and refused every valid DLR; a coding bug must
            // stay a loud 500, not a quiet 403.
            throw WebhookRejected::replayCacheUnavailable();
        }

        if (! $firstUse) {
            throw WebhookRejected::secretRejected('nonce_replayed');
        }
    }

    private function credentialFor(Request $request): ?WebhookCredential
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        if (! is_string($keyId) || $keyId === '') {
            // Unreachable on the real stacks (the binder always sets it); a
            // crash means the route was mounted without its binder, which
            // must never be survivable.
            throw new \LogicException('Webhook auth reached without a resolved key (Doc 02 FR-23/FR-24).');
        }

        return WebhookCredential::query()->where('key_id', $keyId)->first();
    }
}
