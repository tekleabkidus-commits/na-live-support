<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/**
 * The customer half of T-06's input, READ from Doc 08 and Doc 14's own
 * registry by the caller (the pipeline at send time — EC-02 live guards) and
 * handed to the pure function as facts: a phone identity exists
 * (`customer_identities type='phone'`, never the deprecated mirror), the
 * opt-out registry holds a live row for that phone (FR-14), `customers.blocked`
 * (Doc 08 FR-18), `customers.rg_flag` (Doc 08 FR-15), and
 * `MarketingSuppression::isSuppressed` (Doc 08 FR-15's reader contract).
 */
final readonly class CustomerFacts
{
    public function __construct(
        public bool $hasPhone,
        public bool $optedOut,
        public bool $blocked,
        public bool $rgFlagged,
        public bool $marketingSuppressed,
    ) {}
}
