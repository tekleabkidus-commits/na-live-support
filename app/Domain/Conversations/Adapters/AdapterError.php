<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * SendResult's error half (Doc 04 FR-08: error{code, retryable, retry_after?}).
 *
 * `retryable` is derived from the reason — {@see DeliveryFailureReason::retryable()} —
 * so the pair can never disagree. `retryAfterSeconds` is advisory (e.g. a channel's
 * rate-limit hint); the core's backoff schedule (FR-13) still governs, taking the
 * LONGER of the two.
 */
final class AdapterError
{
    public function __construct(
        public readonly DeliveryFailureReason $reason,
        public readonly ?int $retryAfterSeconds = null,
    ) {}

    public function retryable(): bool
    {
        return $this->reason->retryable();
    }
}
