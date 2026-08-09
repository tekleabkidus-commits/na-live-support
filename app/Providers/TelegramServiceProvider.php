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
}
