<?php

declare(strict_types=1);

namespace App\Domain\Sms\Encoding;

/**
 * Doc 14 FR-05 / NFR-06 / EC-09 / A-66 — GSM-7 vs UCS-2 auto-detection and
 * segment math, the ONE implementation the editor's counter, the cost
 * estimate and the accrual at `sent` all call (NFR-06: estimate and accrual
 * agree because they are the same arithmetic on the same string).
 *
 * Rules (A-66, filed): every character in the GSM-7 basic or extension table
 * → GSM-7, extension characters costing two septets; otherwise UCS-2, an
 * astral-plane character costing two UTF-16 code units. A message within the
 * single-segment capacity (160 / 70) is one segment; beyond it, the
 * concatenation header takes room and each segment carries 153 / 67. An
 * empty message is zero segments.
 */
final class Segmenter
{
    public function segment(string $text): Segmentation
    {
        $characters = $text === '' ? [] : mb_str_split($text, 1, 'UTF-8');

        $encoding = Encoding::Gsm7;
        $units = 0;
        foreach ($characters as $c) {
            $septets = Gsm7Charset::septets($c);
            if ($septets === 0) {
                $encoding = Encoding::Ucs2;
                break;
            }
            $units += $septets;
        }

        if ($encoding === Encoding::Ucs2) {
            $units = 0;
            foreach ($characters as $c) {
                $units += mb_ord($c, 'UTF-8') > 0xFFFF ? 2 : 1;
            }
        }

        if ($units === 0) {
            return new Segmentation($encoding, 0, 0, $encoding->singleSegmentUnits(), []);
        }

        if ($units <= $encoding->singleSegmentUnits()) {
            return new Segmentation($encoding, $units, 1, $encoding->singleSegmentUnits(), []);
        }

        $per = $encoding->concatenatedSegmentUnits();
        $segments = intdiv($units + $per - 1, $per);
        $boundaries = [];
        for ($i = 1; $i < $segments; $i++) {
            $boundaries[] = $i * $per;
        }

        return new Segmentation($encoding, $units, $segments, $per, $boundaries);
    }
}
