<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Identity;

use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Customers\Identity\IdentityResolution;

/**
 * The Doc 08 identity-resolver SEAM (Doc 04 FR-10 step 3) — REAL since S08
 * step 2, exactly as the S20 docblock promised ("DOC 08 OWNS EVERYTHING
 * RICHER"): this class is now a one-line delegation to
 * App\Domain\Customers\Identity\IdentityResolution, the FR-07/T-02 resolver.
 *
 * RULED WIRING (A-40, the SCHEMA AMENDMENT 2 countersign): the
 * channel→identity-type mapping is Doc 08's ChannelIdentityMap — this file is
 * the ONLY Doc 04 surface the wiring touches; the signature
 * resolve(string $channel, InboundSender): string is UNCHANGED and
 * NormalizeInbound needed no edit, which is what A-40's blast-radius clause
 * predicted and the ruling chose.
 *
 * Doc 04's contract with identity is still one call: sender → customer_id,
 * existing or shell. Race safety moved from the S20 catch-the-unique-violation
 * shape to T-02's single INSERT … ON CONFLICT upsert inside IdentityResolution
 * (a DB property, proven by ResolverMatrixTest). Every stored field remains
 * channel-reported or staff-collected, never `verified` (N-06).
 */
final class CustomerResolver
{
    public function __construct(private readonly IdentityResolution $identity) {}

    public function resolve(string $channel, InboundSender $sender): string
    {
        return $this->identity->resolveInbound($channel, $sender)->customerId;
    }
}
