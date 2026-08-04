<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;

/**
 * Where enforcement points get a user's CapabilityMap (Doc 03 T-02).
 *
 * Bound to CapabilityMapBuilder directly until S19 step 5 wraps it in the
 * Redis capability cache (`t:{tenant}:cap:{user}`, TTL 10 s + explicit
 * invalidation). Consumers depend on THIS contract so the cache arrives as a
 * decorator swap, not a call-site migration.
 */
interface CapabilityMapProvider
{
    public function mapFor(User $user): CapabilityMap;

    /**
     * One key's verdict. Semantically identical to mapFor()->verdict() —
     * EXCEPT that the cached implementation bypasses the cache entirely for
     * R-04's highest-sensitivity keys (customers.rg_flag.*, audit.view,
     * audit.export): those verdicts are always resolved fresh, so revocation
     * takes effect with ZERO staleness window, not ≤10 s.
     */
    public function verdictFor(User $user, string $key): Verdict;
}
