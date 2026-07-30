<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Foundation\Tenancy\TenantState;

/**
 * The result of resolution: which tenant, in what state, reached how (Doc 01 FR-03).
 *
 * State travels with the id because every entry surface has to make the FR-05
 * decision (staff login? inbound accepted? outbound allowed?) and fetching it
 * separately would either cost a second round trip on the ≤1 ms budget (NFR-02) or
 * tempt each surface to cache its own copy.
 */
final readonly class ResolvedTenant
{
    public function __construct(
        public string $tenantId,
        public TenantState $state,
        public ResolutionSource $source,
        /** Present only for key-based paths; null for session, job, console. */
        public ?string $keyId = null,
    ) {}
}
