<?php

declare(strict_types=1);

namespace App\Support\Quota;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * NFR-06: "exceeding quota returns 429 quota_exceeded with the quota name."
 *
 * The quota name is a top-level problem member, not prose inside `detail`: a client
 * backing off from messages/min behaves differently from one that has exhausted its
 * AI tokens for the day, and parsing prose to find out which is the bug NFR-06's
 * wording exists to prevent.
 */
final class QuotaExceeded extends RuntimeException
{
    private function __construct(
        public readonly Quota $quota,
        public readonly int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forQuota(Quota $quota, int $limit): self
    {
        return new self($quota, $limit, sprintf(
            'Quota "%s" is exhausted (limit %d). The window resets on its own; raising the '
            .'limit is a platform-console action (NFR-06).',
            $quota->value,
            $limit,
        ));
    }

    public function render(): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::QuotaExceeded,
            status: 429,
            detail: $this->getMessage(),
            extra: ['quota' => $this->quota->value],
        );
    }
}
