<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Realtime;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Rbac\Scopes\MembershipSource;
use App\Support\Tenancy\TenantContext;

/**
 * SEC-01's mechanism: a view hash EMBEDS its scope context server-side, so
 * the channel name itself is scope-bound and a subscribe re-authorizes by
 * RE-DERIVATION — the gate recomputes what this viewer's hash for that view
 * would be NOW and compares. A scope change (Doc 03 FR-07) changes the
 * derivation, the stale hash stops authorizing, and the client is FORCED to
 * resubscribe under its new hash (the pruned membership is that new hash's
 * variant) — no revocation bookkeeping, no trusting the client's claim.
 *
 * Signature vocabulary (the scope context, from Doc 03's own resolution —
 * never client input):
 *   per-user views (my_open, following, mentions)  user:{id}
 *   shared views, All scope                        all
 *   shared views, Team/Dept                        team:{sorted}|dept:{sorted}
 *     (their variant ZSETs materialize lazily at step 4 per A-30; the hash
 *     grammar carries them from birth so the channel names never change)
 *   no ticket scope                                none (authorizes nothing)
 */
final class ViewHash
{
    public const PER_USER_VIEWS = ['my_open', 'following', 'mentions'];

    public const SHARED_VIEWS = ['unassigned', 'sla_at_risk', 'expiring_windows', 'rescue'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityMapProvider $maps,
        private readonly MembershipSource $membership,
    ) {}

    /** The viewer's CURRENT scope signature for shared views (Doc 03 resolution). */
    public function scopeSignature(User $user): string
    {
        $level = $this->maps->mapFor($user)->scopeFor('tickets');

        return match ($level) {
            'All' => 'all',
            'Own' => 'own:'.$user->identifier(),
            'Team' => 'team:'.implode(',', $this->sorted($this->membership->teamIdsFor($user))),
            'Dept' => 'dept:'.implode(',', $this->sorted($this->membership->departmentIdsFor($user))),
            default => 'none',
        };
    }

    /** The hash a viewer's channel for $view carries RIGHT NOW. */
    public function forViewer(string $view, User $user): string
    {
        $signature = in_array($view, self::PER_USER_VIEWS, true)
            ? 'user:'.$user->identifier()
            : $this->scopeSignature($user);

        return $this->hash($view, $signature);
    }

    /**
     * The hash the PROJECTOR pushes to for a materialized ZSET key — a pure
     * function of the key's own shape (base keys are the 'all' variant;
     * per-user keys embed their user).
     */
    public function forZsetKey(string $viewKey): string
    {
        if (str_contains($viewKey, ':')) {
            [$view, $userId] = explode(':', $viewKey, 2);

            return $this->hash($view, 'user:'.$userId);
        }

        return $this->hash($viewKey, 'all');
    }

    private function hash(string $view, string $signature): string
    {
        return hash('sha256', sprintf('v1|%s|%s|%s', $this->context->tenantId(), $view, $signature));
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
