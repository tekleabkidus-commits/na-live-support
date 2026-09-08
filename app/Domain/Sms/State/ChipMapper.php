<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

/**
 * Doc 14 §9 / NFR-04 / AC-02 — state → chip, and NOTHING else → chip. The
 * only door takes a SmsDeliveryState, so a carrier word (`success`) has no
 * path to a double check (sabotage (c)). queued/sending: clock · held: moon
 * (D14-D01) · sent: single check · delivered: double check, truthful here per
 * DLR (D14-D01) · undelivered/failed/skipped: red ! · expired: "sent ·
 * unconfirmed", gray — consistent with the Doc 04/05 glyph language, never
 * conflicting with Telegram's single-check ceiling.
 */
final class ChipMapper
{
    public function for(SmsDeliveryState $state): Chip
    {
        [$glyph, $tone] = match ($state) {
            SmsDeliveryState::Queued, SmsDeliveryState::Sending => ['clock', 'neutral'],
            SmsDeliveryState::Held => ['moon', 'neutral'],
            SmsDeliveryState::Sent => ['check', 'neutral'],
            SmsDeliveryState::Delivered => ['double_check', 'success'],
            SmsDeliveryState::Undelivered, SmsDeliveryState::Failed, SmsDeliveryState::Skipped => ['bang', 'danger'],
            SmsDeliveryState::Expired => ['unconfirmed', 'muted'],
        };

        return new Chip($glyph, 'sms.chip.'.$state->value, $tone);
    }
}
