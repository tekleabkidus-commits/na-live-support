<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use SensitiveParameter;

/**
 * The thin Telegram Bot API client (ADR 0012; Doc 05 T-02, SEC-07, T-07).
 *
 * Three properties this class exists to hold, in rank order:
 *
 *   1. PROXY-PINNED. The Guzzle client is constructed by the provider with
 *      the egress proxy from config on its OPTIONS — every request rides it
 *      and no method parameter can opt out. The broken-proxy test (S23
 *      sabotage #11) pins that a dead proxy means a dead client, never a
 *      quiet direct call.
 *   2. TOKEN AT CALL TIME (T-02). The token arrives per call from the bot
 *      registry and never lives on the instance, so one process serves every
 *      bot with no per-bot deploys. It is never interpolated into exception
 *      messages, log lines, or error payloads (T-07) — the NFR-06 scrubber
 *      is the backstop, not the mechanism.
 *   3. TYPED FAILURE. Telegram answering ok:false is TelegramApiError
 *      (error_code, description, retry-after when present); not reaching
 *      Telegram is TelegramTransportError. Step 7's FR-18 classifier maps
 *      BOTH into the frozen DeliveryFailureReason table — this class never
 *      classifies, it only reports faithfully.
 */
final class TelegramBotApi implements TelegramApi
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiBase,
    ) {}

    /**
     * POST {api_base}/bot{token}/{method} and return Telegram's `result`.
     *
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws TelegramApiError when Telegram answers ok:false
     * @throws TelegramTransportError when Telegram cannot be reached
     */
    public function call(#[SensitiveParameter] string $botToken, string $method, array $params = []): array
    {
        try {
            $response = $this->http->post(
                sprintf('%s/bot%s/%s', rtrim($this->apiBase, '/'), $botToken, $method),
                ['json' => (object) $params, 'http_errors' => false],
            );
        } catch (GuzzleException $e) {
            // The exception chain may carry the request URL (which contains
            // the token) — TelegramTransportError re-states the failure
            // WITHOUT chaining it (T-07).
            throw TelegramTransportError::forMethod($method, $e::class, $this->scrubToken($e->getMessage(), $botToken));
        }

        $body = (string) $response->getBody();

        try {
            /** @var array<array-key, mixed> $decoded */
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TelegramTransportError::forMethod($method, 'malformed-json', sprintf('HTTP %d, undecodable body', $response->getStatusCode()));
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw TelegramApiError::fromResponse($method, $response->getStatusCode(), $decoded);
        }

        $result = $decoded['result'] ?? null;

        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * The download URL for a getFile file_path. THE URL IS A SECRET (T-07:
     * it contains the token) — it goes straight into the media fetcher and
     * is never persisted, logged, or returned to any UI.
     */
    public function fileUrl(#[SensitiveParameter] string $botToken, string $filePath): string
    {
        return sprintf('%s/file/bot%s/%s', rtrim($this->apiBase, '/'), $botToken, ltrim($filePath, '/'));
    }

    /** Belt-and-braces: no token fragment survives into an exception message. */
    private function scrubToken(string $message, #[SensitiveParameter] string $botToken): string
    {
        return $botToken === '' ? $message : str_replace($botToken, '[token]', $message);
    }
}
