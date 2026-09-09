<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

/**
 * T-02: accepted{provider_id} | failed{code, retryable}. FR-11's classification
 * (retryable: network / 5xx / throttle → backoff ×4; non-retryable: invalid
 * number, blocked by carrier, no route, auth → failed with reason) is the
 * driver's; `gatewayLevel` marks FR-09's failover class (auth, unreachable,
 * throttled-hard) as opposed to a per-message refusal. `raw` is a BOUNDED
 * excerpt of the response for the attempts log — never the request.
 */
final readonly class SendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerId,
        public ?string $code,
        public bool $retryable,
        public bool $gatewayLevel,
        public ?int $httpStatus,
        public ?string $raw,
    ) {}

    public static function accepted(?string $providerId, ?int $httpStatus, ?string $raw = null): self
    {
        return new self(true, $providerId, null, false, false, $httpStatus, $raw);
    }

    public static function failed(string $code, bool $retryable, bool $gatewayLevel, ?int $httpStatus = null, ?string $raw = null): self
    {
        return new self(false, null, $code, $retryable, $gatewayLevel, $httpStatus, $raw);
    }
}
