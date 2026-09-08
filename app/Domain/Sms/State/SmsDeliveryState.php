<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

/**
 * Doc 14 FR-12 (normative): queued → sending → sent → delivered | undelivered
 * (carrier-reported) | expired (no DLR after 48 h — shown as "sent — delivery
 * unconfirmed", never guessed delivered); plus held (FR-16's queued(held)),
 * skipped{reason} (guards) and failed{reason}. The backing values ARE the
 * `sms_messages.state` CHECK roster and the `sms.<state>` event identifiers —
 * renaming one is a frozen-public-contract change. This is Doc 14's OWN
 * machine on its own entity (R-D14-6.2): Doc 04's DeliveryState governs
 * `messages`; only its LAWS bind here.
 */
enum SmsDeliveryState: string
{
    case Queued = 'queued';
    case Held = 'held';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Expired = 'expired';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Undelivered, self::Expired, self::Skipped, self::Failed => true,
            default => false,
        };
    }

    /** FR-13 precedence: the rank a DLR must exceed to move the row. */
    public function precedence(): int
    {
        return match ($this) {
            self::Queued, self::Held => 0,
            self::Sending => 1,
            self::Sent, self::Expired => 2,
            self::Undelivered => 3,
            self::Delivered => 4,
            self::Skipped, self::Failed => -1,
        };
    }
}
