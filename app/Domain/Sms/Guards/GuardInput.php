<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

use App\Domain\Sms\Catalog\SendableEvent;
use DateTimeImmutable;

/**
 * T-06: (customer, event, template, time). `now` is an INPUT — the guard
 * reads no clock (§8). `lastSameSendAt` is the DB-time `queued_at` of the
 * most recent message with the same (event, ticket, phone), read by the
 * caller (FR-03's duplicate suppression, 10 min by the `sms.duplicate_window_minutes`
 * setting). `inQuietHours` is computed by QuietHoursWindow (FR-16) from the
 * setting or the linked calendar, in the tenant timezone, at evaluation time.
 */
final readonly class GuardInput
{
    public function __construct(
        public SendableEvent $event,
        public CustomerFacts $customer,
        public TemplateFacts $template,
        public ModuleFacts $module,
        public DateTimeImmutable $now,
        public ?DateTimeImmutable $lastSameSendAt,
        public int $duplicateWindowMinutes,
        public bool $inQuietHours,
    ) {}
}
