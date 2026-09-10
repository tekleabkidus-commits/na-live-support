<?php

declare(strict_types=1);

namespace App\Domain\Sms\Pipeline;

/**
 * What EnqueueSms did with a SendRequest. `refused_no_phone` is the one
 * row-less outcome: `sms_messages.phone` is NOT NULL by physics, so a customer
 * with no phone identity leaves nothing to write — the caller gets the reason
 * instead of a row (the notify door surfaces it; a consumer logs it).
 */
final readonly class EnqueueResult
{
    private function __construct(
        public string $status,
        public ?string $smsMessageId,
        public ?string $reason,
    ) {}

    public static function queued(string $smsMessageId): self
    {
        return new self('queued', $smsMessageId, null);
    }

    public static function held(string $smsMessageId, string $reason): self
    {
        return new self('held', $smsMessageId, $reason);
    }

    public static function skipped(string $smsMessageId, string $reason): self
    {
        return new self('skipped', $smsMessageId, $reason);
    }

    public static function refusedNoPhone(): self
    {
        return new self('refused_no_phone', null, 'no_phone');
    }
}
