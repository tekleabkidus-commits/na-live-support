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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Doc 14 T-02 / FR-10 / AC-08 / §21 — the `http_generic` driver: CONFIG-MAPPED
 * (GatewayConfig), so an unlisted aggregator onboards without code. The seeded
 * GeezSMS mapping (vendor notes §3.4/§9/§16) rides this class with ZERO
 * GeezSMS-specific code — HttpGenericOnboardingTest asserts no file under
 * Drivers/ names the vendor.
 *
 * Every call rides the egress proxy (ADR 0008; an empty proxy refuses). The
 * credential travels ONLY where the mapping's auth style says (a form field, a
 * bearer, a named header) — never a query string (R-D14-1.6). Classification
 * (FR-11): 2xx → accepted (+ the id at `send.response.id_path` when present —
 * §4: the platform's own id stays the correlation key); 429 / 5xx / transport →
 * failed retryable; 401/403 → failed non-retryable GATEWAY-LEVEL (FS-01: the
 * failover class); other 4xx → failed non-retryable per message. The vendor's
 * length cap and template marker are refused BEFORE a byte leaves (§7, §12).
 *
 * WHAT IS NEVER LOGGED: the request body, the credential, the callback URL,
 * the full phone (only its masked form), the vendor response body beyond a
 * bounded excerpt kept on the attempts row (SEC-01/SEC-02; SmsEgressGuardTest).
 */
final class HttpGenericDriver implements DriverContract
{
    private const RAW_EXCERPT = 240;

    public function send(RenderedMessage $message, GatewayConfig $config, ?string $credential): SendResult
    {
        $max = $config->maxChars();
        if ($max !== null && mb_strlen($message->text, 'UTF-8') >= $max) {
            return SendResult::failed('vendor_length_cap', false, false);
        }
        $marker = $config->refuseMarker();
        if ($marker !== null && str_contains($message->text, $marker)) {
            return SendResult::failed('vendor_template_marker', false, false);
        }

        $fields = $config->fields();
        $to = $config->sendString('to_format', 'e164') === 'digits' ? ltrim($message->phoneE164, '+') : $message->phoneE164;
        $payload = [$fields['to'] => $to, $fields['text'] => $message->text];
        if (isset($fields['sender']) && $message->senderId !== null) {
            $payload[$fields['sender']] = $message->senderId;
        }
        if (isset($fields['callback']) && $message->callbackUrl !== null) {
            $payload[$fields['callback']] = $message->callbackUrl;
        }

        $auth = $config->auth();
        $request = $this->client();
        if ($auth['style'] === 'form_field') {
            if ($credential === null || ($auth['field'] ?? null) === null) {
                return SendResult::failed('credential_missing', false, true);
            }
            $payload[$auth['field']] = $credential;
        } elseif ($auth['style'] === 'bearer') {
            if ($credential === null) {
                return SendResult::failed('credential_missing', false, true);
            }
            $request = $request->withToken($credential);
        } elseif ($auth['style'] === 'header') {
            if ($credential === null || ($auth['header'] ?? null) === null) {
                return SendResult::failed('credential_missing', false, true);
            }
            $request = $request->withHeaders([$auth['header'] => $credential]);
        }

        $url = $config->baseUrl().'/'.ltrim($config->sendString('path', '/send'), '/');
        $method = strtoupper($config->sendString('method', 'POST'));
        if ($method !== 'POST') {
            // R-D14-1.6: a GET send puts the credential in a URL that lands in access logs — refused by the driver, not by the vendor.
            return SendResult::failed('get_send_refused', false, true);
        }
        $request = match ($config->sendString('content_type', 'form')) {
            'json' => $request->asJson(),
            'multipart' => $request->asMultipart(),
            default => $request->asForm(),
        };

        try {
            $response = $request->post($url, $payload);
        } catch (ConnectionException $e) {
            $this->logAttempt($message, null, 'transport', $config);

            return SendResult::failed('transport', true, true);
        }

        return $this->classify($response, $message, $config);
    }

    public function parseDlr(array $payload, GatewayConfig $config): ParsedDlr
    {
        $rawStatus = self::path($payload, $config->dlrString('status_path') ?? 'status');
        $rawStatus = is_scalar($rawStatus) ? (string) $rawStatus : '';
        $providerId = self::path($payload, $config->dlrString('id_path') ?? 'message_id');
        $code = self::path($payload, $config->dlrString('code_path') ?? 'code');
        $normalized = $config->dlrMapping()[$rawStatus] ?? null;
        $status = $normalized === null ? DlrStatus::Unknown : (DlrStatus::tryFrom($normalized) ?? DlrStatus::Unknown);

        return new ParsedDlr(
            is_scalar($providerId) ? (string) $providerId : null,
            $status,
            mb_substr($rawStatus, 0, 64),
            is_scalar($code) && (string) $code !== '' ? mb_substr((string) $code, 0, 64) : null,
        );
    }

    public function capabilities(GatewayConfig $config): Capabilities
    {
        return $config->capabilities();
    }

    public function healthy(GatewayConfig $config, ?string $credential): bool
    {
        $path = $config->healthProbePath();
        if ($path === null) {
            return true; // no probe configured: the half-open state lets the next real send be the probe
        }
        try {
            $request = $this->client();
            $auth = $config->auth();
            if ($auth['style'] === 'bearer' && $credential !== null) {
                $request = $request->withToken($credential);
            } elseif ($auth['style'] === 'header' && $credential !== null && $auth['header'] !== null) {
                $request = $request->withHeaders([$auth['header'] => $credential]);
            }

            return $request->get($config->baseUrl().'/'.ltrim($path, '/'))->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    private function classify(Response $response, RenderedMessage $message, GatewayConfig $config): SendResult
    {
        $status = $response->status();
        $raw = mb_substr($response->body(), 0, self::RAW_EXCERPT);
        if ($response->successful()) {
            $idPath = $config->responseIdPath();
            $json = $response->json();
            $id = $idPath === null || ! is_array($json) ? null : self::path($json, $idPath);
            $providerId = is_scalar($id) && (string) $id !== '' ? (string) $id : null;
            $this->logAttempt($message, $status, 'accepted', $config);

            return SendResult::accepted($providerId, $status, $raw);
        }
        if ($status === 429 || $status >= 500) {
            $this->logAttempt($message, $status, 'retryable', $config);

            // 429 and 5xx are the GATEWAY's condition, never this message's: gateway-level, retryable (FR-11)
            return SendResult::failed($status === 429 ? 'throttled' : 'upstream_'.$status, true, true, $status, $raw);
        }
        if ($status === 401 || $status === 403) {
            $this->logAttempt($message, $status, 'auth', $config);

            return SendResult::failed('auth_rejected', false, true, $status, $raw);
        }
        $this->logAttempt($message, $status, 'rejected', $config);

        return SendResult::failed('rejected_'.$status, false, false, $status, $raw);
    }

    /** The ONLY log line the driver writes: status + class + the masked phone — never a body, a credential or the callback URL. */
    private function logAttempt(RenderedMessage $message, ?int $status, string $class, GatewayConfig $config): void
    {
        Log::info('sms driver attempt', [
            'sms_message_id' => $message->messageId,
            'gateway_base' => parse_url($config->baseUrl(), PHP_URL_HOST),
            'http_status' => $status,
            'class' => $class,
            'to_masked' => self::mask($message->phoneE164),
        ]);
    }

    public static function mask(string $e164): string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';

        return strlen($digits) < 8 ? '••••' : '+'.substr($digits, 0, 3).' •• ••• '.substr($digits, -4);
    }

    private function client(): PendingRequest
    {
        $proxy = config('sms.egress_proxy');
        if (! is_string($proxy) || $proxy === '') {
            throw new \RuntimeException('sms.egress_proxy is empty — direct egress is refused (ADR 0008)');
        }
        $timeout = config('sms.http.timeout_s');
        $connect = config('sms.http.connect_timeout_s');

        return Http::withOptions([
            'proxy' => $proxy,
            'timeout' => is_int($timeout) ? $timeout : 15,
            'connect_timeout' => is_int($connect) ? $connect : 5,
        ])->acceptJson();
    }

    /**
     * @param  array<mixed>  $data  a decoded JSON document — keys are whatever the vendor sent
     */
    public static function path(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}
