<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Api;

use RuntimeException;

/**
 * Telegram could not be reached (network, proxy, TLS, undecodable body).
 *
 * Deliberately NOT constructed with `previous`: Guzzle exceptions carry the
 * request URL, and a Telegram request URL contains the token (T-07). The
 * original failure class and a token-scrubbed summary travel as strings.
 * Always retryable-shaped; step 7's classifier maps it.
 */
final class TelegramTransportError extends RuntimeException
{
    private function __construct(
        public readonly string $method,
        public readonly string $failureClass,
        string $summary,
    ) {
        parent::__construct(sprintf('Telegram %s unreachable (%s): %s', $method, $failureClass, $summary));
    }

    public static function forMethod(string $method, string $failureClass, string $summary): self
    {
        return new self($method, $failureClass, $summary);
    }
}
