<?php

declare(strict_types=1);

namespace App\Domain\Sms\Cost;

/**
 * Doc 14 FR-07 / FR-19 — one rate-card row: the price per SEGMENT for a
 * country prefix on a gateway, in the card's currency (embedded in the
 * gateway's config jsonb, T-01). Money is a decimal STRING end to end
 * (numeric(12,4) in the row; never a float).
 */
final readonly class RateCard
{
    public function __construct(
        public string $countryPrefix,
        public string $pricePerSegment,
        public string $currency,
    ) {
        if (preg_match('/^\d+(\.\d{1,4})?$/', $pricePerSegment) !== 1) {
            throw new \InvalidArgumentException('a rate-card price is a non-negative decimal string with up to four places');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('a rate-card currency is an ISO-4217 code');
        }
    }

    /**
     * @param  array{country_prefix: string, price_per_segment: string|int|float, currency: string}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self((string) $row['country_prefix'], is_string($row['price_per_segment']) ? $row['price_per_segment'] : number_format((float) $row['price_per_segment'], 4, '.', ''), (string) $row['currency']);
    }
}
