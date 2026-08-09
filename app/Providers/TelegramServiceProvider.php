<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Telegram\Api\TelegramApi;
use App\Domain\Telegram\Api\TelegramBotApi;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

/**
 * Doc 05 wiring (ADR 0012).
 *
 * THE proxy pin lives here and only here: the Guzzle client is constructed
 * with the egress proxy on its options, so every TelegramBotApi request
 * rides it and no call site can opt out (SEC-07; the S23 broken-proxy test
 * pins the absence of a direct-egress fallback).
 */
final class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramBotApi::class, static function (): TelegramBotApi {
            /** @var string $proxy */
            $proxy = config('telegram.egress_proxy');
            /** @var string $apiBase */
            $apiBase = config('telegram.api_base');
            $timeout = config('telegram.timeout_seconds');
            $connectTimeout = config('telegram.connect_timeout_seconds');

            return new TelegramBotApi(
                http: new Client([
                    'proxy' => $proxy,
                    'timeout' => is_numeric($timeout) ? (int) $timeout : 30,
                    'connect_timeout' => is_numeric($connectTimeout) ? (int) $connectTimeout : 10,
                ]),
                apiBase: $apiBase,
            );
        });

        $this->app->alias(TelegramBotApi::class, TelegramApi::class);
    }

    public function boot(): void
    {
        // The FIRST REAL channel registration (T-01/T-02): one adapter for
        // channel 'telegram' on the constitutional telegram-in/out families.
        // Channel-level capability envelope per T-01; per-conversation
        // none/duration nuance is Doc 05 window logic (step 8). The OUTBOUND
        // per-message token resolution wires at step 7 — until then a send
        // through this instance answers FS-03's credentials_unavailable
        // (retryable), never a wrong-bot token.
        $this->app->make(\App\Domain\Conversations\Adapters\AdapterRegistry::class)->register(
            'telegram',
            new \App\Domain\Telegram\TelegramAdapter(
                api: $this->app->make(TelegramApi::class),
                capabilities: new \App\Domain\Conversations\Adapters\Capabilities(
                    maxText: 4096,
                    mediaTypes: ['photo', 'voice', 'video', 'audio', 'document', 'sticker'],
                    supportsTyping: true,
                    supportsEdit: true,
                    supportsDelete: true,
                    supportsButtons: true,
                    replyWindow: \App\Domain\Conversations\Adapters\ReplyWindow::duration(86_400),
                ),
                botKey: '',
                tokenResolver: static function (): string {
                    throw new \RuntimeException('outbound token resolution wires at S23 step 7 (FS-03 posture until then).');
                },
            ),
            'telegram-in',
            'telegram-out',
        );

        // A-26: the telegram-file resolver — the opaque persisted reference
        // becomes a fetchable URL PER FETCH ATTEMPT, inside the chain's own
        // try. The tokened URL is a LOCAL here and nowhere else (T-07).
        // Telegram's own refusals classify per A-26 condition 3:
        // "file is too big" (the 20 MB bot ceiling, EC-07) -> MediaTooLarge
        // (terminal reject through the chain's existing door); anything
        // else -> RuntimeException (the hold/retry door).
        $this->app->make(\App\Domain\Conversations\Media\SourceUrlResolvers::class)
            ->register('telegram-file', function (string $reference): string {
                if (preg_match('~^telegram-file:([0-9a-f-]{36})/(.+)$~', $reference, $m) !== 1) {
                    throw new \RuntimeException('malformed telegram-file reference');
                }

                $bot = \App\Models\TelegramBot::query()->findOrFail($m[1]);
                $token = $bot->bot_token;
                if (! is_string($token)) {
                    throw new \RuntimeException('bot has no stored token (FS-03 posture: retryable)');
                }

                /** @var TelegramApi $api */
                $api = $this->app->make(TelegramApi::class);

                try {
                    $file = $api->call($token, 'getFile', ['file_id' => $m[2]]);
                } catch (\App\Domain\Telegram\Api\TelegramApiError $e) {
                    if (str_contains(strtolower($e->description), 'too big')) {
                        // EC-07 -> the chain's terminal-reject door. The cap
                        // communicated is Telegram's own 20 MB bot ceiling.
                        throw new \App\Domain\Conversations\Media\MediaTooLarge(20 * 1024 * 1024);
                    }

                    throw new \RuntimeException('getFile refused: '.$e->description, previous: $e);
                }

                $path = $file['file_path'] ?? null;
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('getFile answered without file_path');
                }

                return $api->fileUrl($token, $path);
            });
    }
}
