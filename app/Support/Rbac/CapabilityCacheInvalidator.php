<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Support\Cache\TenantCache;
use Throwable;

/**
 * FR-07's propagation trigger: every rbac mutation calls this, synchronously,
 * in the mutating action — the cache lives on the SHARED volatile Redis, so
 * one forget IS cluster-wide; there is no per-process copy to chase with
 * pub/sub, and FS-02's answer when a forget is lost anyway is the 10 s TTL.
 *
 * It also forgets Doc 02's per-token scope cache entries for the affected
 * users (their EC-06 cache composes UNDER this one): without that, a token
 * check could serve a stale intersection for up to 10 s + 10 s. TokenScopeGate
 * itself is untouched (the T-05 seam contract) — only its cache KEYS are
 * addressed, through the same TenantCache surface it writes them with.
 *
 * Failures are swallowed after a metric: an unreachable cache means the
 * entries EXPIRE within 10 s anyway (FS-01/FS-02) — an invalidation that
 * cannot reach Redis must not fail the mutation that requested it.
 */
final class CapabilityCacheInvalidator
{
    public function __construct(private readonly TenantCache $cache) {}

    public function forgetUser(User $user): void
    {
        $this->forgetUserId($user->identifier());
    }

    public function forgetUserId(string $userId): void
    {
        try {
            $this->cache->forget(CachedCapabilityMaps::suffix($userId));
            $this->forgetTokenScopes($userId);
        } catch (Throwable) {
            $this->recordLost();
        }
    }

    /**
     * A role-level change touches every holder: forget each member's map.
     * Bounded by role membership, not tenant size — §12's "role save
     * affecting 200 users ≤ 2 s" is 200 Redis DELs.
     */
    public function forgetRoleMembers(Role $role): void
    {
        foreach ($role->users()->get(['id', 'tenant_id']) as $user) {
            $this->forgetUserId($user->identifier());
        }
    }

    /**
     * Doc 02's token-scope cache keys for this user's tokens. The gate caches
     * per token id under the tenant prefix; the token ids are one indexed
     * query away. The suffix mirrors TokenScopeGate ('tokscope:' — that file
     * must not change per the T-05 seam contract, so the literal lives here
     * with a test pinning the two together).
     */
    private function forgetTokenScopes(string $userId): void
    {
        $tokens = \App\Models\PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->get();

        foreach ($tokens as $token) {
            $this->cache->forget('tokscope:'.$token->identifier());
        }
    }

    private function recordLost(): void
    {
        \Illuminate\Support\Facades\Log::warning('capability cache invalidation lost — TTL bounds staleness (FS-02)', [
            'security_metric' => 'rbac.capability_invalidation_lost',
        ]);
    }
}
