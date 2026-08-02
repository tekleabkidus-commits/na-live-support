<?php

declare(strict_types=1);

namespace App\Support\Auth\Tokens;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Cache\TenantCache;
use App\Support\Rbac\EffectivePermissions;

/**
 * The T-05 intersection engine (Doc 02 T-05, FR-18, FR-20, AC-05, EC-06).
 *
 * `token scope = token.abilities ∩ owner.effective_permissions`, evaluated at
 * request time. What is stored on the token is a CEILING, never a grant: an
 * ability the owner has since lost stops working, and no token can ever exceed
 * its owner (FR-18's "intersection evaluated at request time").
 *
 * THE 10-SECOND CACHE IS A CONTRACT, NOT AN OPTIMISATION DETAIL. T-05 fixes it
 * ("cached 10 s per token") and EC-06 turns it into the user-facing bound: a
 * lost permission keeps working for AT MOST 10 s, and the AC-05 suite proves
 * both halves — that the stale verdict IS served inside the window (removing
 * the cache would silently change the documented behaviour) and that it is
 * gone after it. C-07's ledger: key `t:{tenant}:tokscope:{token_id}`,
 * mandatory TTL 10 s, invalidated by TTL expiry (permission loss has no event
 * until Doc 03's rbac events land) and explicitly on revocation.
 *
 * Doc 03 NOTE: swap the EffectivePermissions binding, not this class — the
 * arithmetic and the cache bound stay exactly as they are.
 */
final class TokenScopeGate
{
    public const CACHE_TTL_SECONDS = 10;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly EffectivePermissions $effective,
    ) {}

    public function allows(PersonalAccessToken $token, User $owner, string $permissionKey): bool
    {
        return in_array($permissionKey, $this->scopeSet($token, $owner), true);
    }

    /**
     * The token's LIVE scope set — abilities ∩ owner's effective permissions.
     *
     * @return list<string>
     */
    public function scopeSet(PersonalAccessToken $token, User $owner): array
    {
        $suffix = 'tokscope:'.$token->identifier();

        /** @var mixed $cached */
        $cached = $this->cache->get($suffix);

        if (is_array($cached)) {
            /** @var list<string> $cached */
            return $cached;
        }

        $abilities = $token->abilities;
        /** @var list<string> $abilities */
        $abilities = is_array($abilities) ? array_values(array_filter($abilities, is_string(...))) : [];

        $set = array_values(array_intersect($abilities, $this->effective->for($owner)));

        $this->cache->put(
            $suffix,
            $set,
            self::CACHE_TTL_SECONDS,
            'TTL expiry (EC-06 bounds staleness at 10 s); TokenScopeGate::forget() on revocation',
        );

        return $set;
    }

    /**
     * Revocation must not wait out the window: a revoked token is dead at the
     * door on its next request anyway, but a stale allow cached for a token
     * that was just revoked-and-recreated under the same id would be a lie.
     */
    public function forget(PersonalAccessToken $token): void
    {
        $this->cache->forget('tokscope:'.$token->identifier());
    }
}
