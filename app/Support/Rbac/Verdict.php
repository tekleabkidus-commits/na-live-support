<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use DateTimeImmutable;

/**
 * One key's resolved verdict for one user (Doc 03 FR-05, T-02).
 *
 * Granter and expiry ride along ONLY when the source is an override — they are
 * the §8 explorer's "Denied by user override, set by Hanna, expires Aug 4" and
 * EC-02's "your temporary access expired" attribution. Identifiers, not
 * denormalized names: presentation resolves them.
 */
final readonly class Verdict
{
    public function __construct(
        public bool $allowed,
        public VerdictSource $source,
        public ?string $grantedBy = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
