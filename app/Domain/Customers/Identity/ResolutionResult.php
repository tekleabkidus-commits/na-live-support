<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

/**
 * FR-07's output shape: {customer, created}. Doc 04's seam consumes only the
 * id; Doc 08's own doors (and Doc 13's adapter later) read the flag.
 */
final class ResolutionResult
{
    public function __construct(
        public readonly string $customerId,
        public readonly bool $created,
    ) {}
}
