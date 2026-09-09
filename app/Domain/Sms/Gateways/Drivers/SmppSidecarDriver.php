<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways\Drivers;

use App\Domain\Sms\Gateways\Capabilities;
use App\Domain\Sms\Gateways\DriverContract;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\ParsedDlr;
use App\Domain\Sms\Gateways\RenderedMessage;
use App\Domain\Sms\Gateways\SendResult;
use App\Domain\Sms\State\DlrStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Doc 14 T-03 / FR-10 / FS-02 / AC-08 — the SMPP sidecar driver: "the platform
 * treats it as just another HTTP driver". The CONTRACT half is Doc 14's:
 * `POST /send` {message_id, to, text, sender, callback}, `GET /health`, DLR
 * webhooks back to Doc 02 FR-24's endpoint, over PRIVATE HTTPS with mTLS (the
 * client certificate from `sms.sidecar.mtls_dir`; our own service — not
 * third-party egress, so no proxy). The SMPP bind, enquire_link keepalives and
 * window management live in the sidecar (Go/Node), never in PHP (ADR 0001);
 * the sidecar's PACKAGING and the real bind are [D18]. A sidecar restart is
 * INVISIBLE to the pipeline: a transport failure is `retryable` (T-03).
 */
final class SmppSidecarDriver implements DriverContract
{
    public function send(RenderedMessage $message, GatewayConfig $config, ?string $credential): SendResult
    {
        try {
            $response = $this->client()->post($config->baseUrl().'/send', [
                'message_id' => $message->messageId,
                'to' => $message->phoneE164,
                'text' => $message->text,
                'sender' => $message->senderId,
                'callback' => $message->callbackUrl,
            ]);
        } catch (ConnectionException) {
            Log::info('sms sidecar attempt', ['sms_message_id' => $message->messageId, 'class' => 'transport', 'to_masked' => HttpGenericDriver::mask($message->phoneE164)]);

            return SendResult::failed('sidecar_unreachable', true, true);
        }
        $status = $response->status();
        Log::info('sms sidecar attempt', ['sms_message_id' => $message->messageId, 'http_status' => $status, 'to_masked' => HttpGenericDriver::mask($message->phoneE164)]);
        if ($response->successful()) {
            $id = $response->json('message_id');

            return SendResult::accepted(is_scalar($id) ? (string) $id : null, $status);
        }
        if ($status >= 500 || $status === 429) {
            return SendResult::failed('sidecar_'.$status, true, true, $status);
        }

        return SendResult::failed('sidecar_rejected_'.$status, false, false, $status);
    }

    public function parseDlr(array $payload, GatewayConfig $config): ParsedDlr
    {
        $raw = is_scalar($payload['status'] ?? null) ? (string) $payload['status'] : '';
        $status = DlrStatus::tryFrom($raw) ?? DlrStatus::Unknown;
        $id = $payload['message_id'] ?? null;
        $code = $payload['code'] ?? null;

        return new ParsedDlr(is_scalar($id) ? (string) $id : null, $status, mb_substr($raw, 0, 64), is_scalar($code) ? (string) $code : null);
    }

    public function capabilities(GatewayConfig $config): Capabilities
    {
        return $config->capabilities();
    }

    /** T-03: the sidecar's own health endpoint, scraped into the gateway health rollup. */
    public function healthy(GatewayConfig $config, ?string $credential): bool
    {
        try {
            $response = $this->client()->get($config->baseUrl().'/health');

            return $response->successful() && $response->json('bind') === 'up';
        } catch (ConnectionException) {
            return false;
        }
    }

    private function client(): PendingRequest
    {
        $configured = config('sms.sidecar.mtls_dir');
        $dir = is_string($configured) ? rtrim($configured, '/') : '';
        $timeout = config('sms.sidecar.timeout_s');

        return Http::withOptions([
            'verify' => $dir.'/ca.crt',
            'cert' => $dir.'/client.crt',
            'ssl_key' => $dir.'/client.key',
            'timeout' => is_int($timeout) ? $timeout : 10,
            'connect_timeout' => 3,
        ])->acceptJson()->asJson();
    }
}
