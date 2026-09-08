<?php

declare(strict_types=1);

namespace App\Domain\Sms\Encoding;

/**
 * Doc 14 FR-05 / A-66: GSM-7 (160 septets single, 153 concatenated) or UCS-2
 * (70 code units single, 67 concatenated). The backing value is the
 * `sms_template_versions.encoding` / `sms_messages.encoding` column value
 * and the editor badge's key ("UCS-2 · 70/segment" — the badge copy stays the
 * document's; the counter computes with the header).
 */
enum Encoding: string
{
    case Gsm7 = 'gsm7';
    case Ucs2 = 'ucs2';

    public function singleSegmentUnits(): int
    {
        return $this === self::Gsm7 ? 160 : 70;
    }

    public function concatenatedSegmentUnits(): int
    {
        return $this === self::Gsm7 ? 153 : 67;
    }
}
