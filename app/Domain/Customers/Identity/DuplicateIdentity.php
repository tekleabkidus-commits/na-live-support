<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use Exception;

/**
 * FR-09's block, as a typed refusal: the value already belongs to ANOTHER
 * customer (one identity, one customer — BR-04). The door renders C02's
 * frame from these fields: the block names the other profile (scope-checked
 * at render — C02-v2's boundary) and offers "merge instead?" — the shortcut
 * is an OFFER, never an action taken here.
 */
final class DuplicateIdentity extends Exception
{
    public function __construct(
        public readonly string $existingCustomerId,
        public readonly string $type,
    ) {
        parent::__construct("that {$type} already belongs to another customer (FR-09) — merge instead?");
    }
}
