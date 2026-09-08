<?php

declare(strict_types=1);

namespace App\Domain\Sms\Cost;

/**
 * Doc 14 FR-19 / NFR-06 / BR-07 — cost = segments × the rate-card price per
 * (gateway, country). The SAME arithmetic serves the editor's estimate row
 * (FR-05) and the accrual at `sent` (step 11): parity is one function, the
 * only variable between the two is the segment count (A-66's one-segment
 * margin). Decimal strings via bcmul — never a float.
 */
final class CostEstimate
{
    public static function amount(int $segments, RateCard $card): string
    {
        $price = $card->pricePerSegment;
        if (! is_numeric($price)) {
            throw new \InvalidArgumentException('a rate-card price is numeric'); // RateCard already guarantees it; PHPStan narrows here
        }

        return bcmul((string) $segments, $price, 4);
    }
}
