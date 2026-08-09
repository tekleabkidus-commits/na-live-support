<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Api;

use RuntimeException;

/**
 * Telegram answered, and the answer is no (ok:false).
 *
 * Carries exactly what FR-18's classifier (step 7) needs — HTTP status,
 * Telegram's error_code and description, and parameters.retry_after when
 * present — and nothing that is secret: no token, no URL, no raw request.
 */
final class TelegramApiError extends RuntimeException
{
    private function __construct(
        public readonly string $method,
        public readonly int $httpStatus,
        public readonly int $errorCode,
        public readonly string $description,
        public readonly ?int $retryAfter,
    ) {
        parent::__construct(sprintf(
            'Telegram %s failed: HTTP %d, error_code %d, %s%s',
            $method,
            $httpStatus,
            $errorCode,
            $description,
            $retryAfter !== null ? sprintf(' (retry after %d s)', $retryAfter) : '',
        ));
    }

    /** @param array<array-key, mixed> $decoded */
    public static function fromResponse(string $method, int $httpStatus, array $decoded): self
    {
        $parameters = $decoded['parameters'] ?? [];
        $retryAfter = is_array($parameters) && is_int($parameters['retry_after'] ?? null)
            ? $parameters['retry_after']
            : null;

        return new self(
            method: $method,
            httpStatus: $httpStatus,
            errorCode: is_int($decoded['error_code'] ?? null) ? $decoded['error_code'] : $httpStatus,
            description: is_string($decoded['description'] ?? null) ? $decoded['description'] : 'no description',
            retryAfter: $retryAfter,
        );
    }
}
