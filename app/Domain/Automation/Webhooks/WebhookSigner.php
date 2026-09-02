<?php

declare(strict_types=1);

namespace App\Domain\Automation\Webhooks;

use App\Http\Middleware\VerifyWebhookSecret;

/**
 * Doc 12 SEC-02 "signed payloads (Doc 01)" — the platform's outbound
 * signature is the MIRROR of its inbound body_hmac contract (Doc 02 FR-24,
 * VerifyWebhookSecret): X-Webhook-Signature = hex HMAC-SHA256 over
 * "{timestamp}.{nonce}.{raw body}" with the tenant's
 * `tenant.outbound_webhook_secret` (Doc 01's registered setting), plus
 * X-Webhook-Timestamp and X-Webhook-Nonce. Timestamp and nonce are INSIDE the
 * signature for the same reason the inbound side insists on it: a body-only
 * HMAC is replayable forever. Pure; the header names are the middleware's own
 * constants so the two sides cannot drift apart silently.
 */
final class WebhookSigner
{
    /** @return array<string, string> the three headers */
    public static function headers(string $secret, string $body, int $timestamp, string $nonce): array
    {
        return [
            VerifyWebhookSecret::SIGNATURE_HEADER => self::signature($secret, $body, $timestamp, $nonce),
            VerifyWebhookSecret::TIMESTAMP_HEADER => (string) $timestamp,
            VerifyWebhookSecret::NONCE_HEADER => $nonce,
        ];
    }

    public static function signature(string $secret, string $body, int $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
    }
}
