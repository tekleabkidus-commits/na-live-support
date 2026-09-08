<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

/**
 * Doc 14 FR-13 / T-02 `parseDlr(payload) → {provider_id, status, raw}` — the
 * machine's NORMALIZED carrier vocabulary. Each driver's mapping table
 * (per-driver, T-02; the `http_generic` config's DLR mapping) translates a
 * raw carrier status to one of these; the raw code is retained on the row.
 *
 * `Accepted` is submission acceptance — the GeezSMS profile's `success` maps
 * HERE and nowhere else (R-D14-1.5): it implies NO delivery. Only `Delivered`
 * does, and only a mapping table row naming a handset delivery may produce it.
 */
enum DlrStatus: string
{
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Unknown = 'unknown';

    public function impliesDelivery(): bool
    {
        return $this === self::Delivered;
    }
}
