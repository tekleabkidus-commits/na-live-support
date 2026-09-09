<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

/**
 * Doc 14 T-02 — the driver interface, EXACTLY:
 *   send(RenderedMessage) → {provider_id, accepted | failed{code, retryable}}
 *   parseDlr(payload)     → {provider_id, status, raw}
 *   capabilities()        → {inbound_stop, alphanumeric_sender, max_throughput}
 *
 * `http_generic` is config-mapped (URL, method, field mapping, auth style —
 * GatewayConfig) so an unlisted local aggregator onboards WITHOUT code
 * (AC-08); the sidecar driver is code over private HTTP + mTLS (T-03). A named
 * aggregator driver is §19 future expansion. No driver ever logs a request
 * body, a token or the assembled callback URL (SEC-01/SEC-02; the structural
 * test SmsEgressGuardTest plants → detects → removes).
 */
interface DriverContract
{
    public function send(RenderedMessage $message, GatewayConfig $config, ?string $credential): SendResult;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseDlr(array $payload, GatewayConfig $config): ParsedDlr;

    public function capabilities(GatewayConfig $config): Capabilities;

    /** FR-09 half-open probing / T-03 health scrape: true when the gateway answers. */
    public function healthy(GatewayConfig $config, ?string $credential): bool;
}
