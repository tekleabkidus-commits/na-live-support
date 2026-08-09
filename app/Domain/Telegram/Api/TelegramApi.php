<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Api;

use SensitiveParameter;

/**
 * The Bot API seam (ADR 0012). One production implementation
 * ({@see TelegramBotApi}, proxy-pinned); the conformance suite substitutes a
 * scripted one because the suite "never depends on a mock HTTP service being
 * up" (AdapterConformanceCase's own rule).
 */
interface TelegramApi
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws TelegramApiError
     * @throws TelegramTransportError
     */
    public function call(#[SensitiveParameter] string $botToken, string $method, array $params = []): array;

    /** The getFile download URL — A SECRET (T-07: it contains the token). */
    public function fileUrl(#[SensitiveParameter] string $botToken, string $filePath): string;
}
