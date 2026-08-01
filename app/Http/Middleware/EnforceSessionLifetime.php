<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\Sessions\SessionLifetimePolicy;
use App\Support\Auth\Sessions\SessionRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-11's timeouts, and FR-12's "last active" column, on every authenticated
 * request.
 *
 * Runs after the session is loaded and the principal resolved, because both
 * decisions need the user. Its two jobs are deliberately in this order:
 *
 *   1. EXPIRE if either clock has run out — an expired session must not be
 *      refreshed by the very request that discovers it has expired.
 *   2. TOUCH otherwise, which is what makes the idle clock roll and what the
 *      devices screen reads as "last active".
 *
 * Reversing them would make every session immortal: touch-then-check always sees
 * a session that was active a millisecond ago.
 */
final class EnforceSessionLifetime
{
    public function __construct(
        private readonly SessionLifetimePolicy $policy,
        private readonly SessionRegistry $registry,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('staff');

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = CarbonImmutable::now();

        $establishedAt = $this->timestamp($session->get(SessionLifetimePolicy::ESTABLISHED_AT));
        $lastActivityAt = $this->timestamp($session->get(SessionLifetimePolicy::LAST_ACTIVITY_AT));

        if ($this->policy->hasExpired($establishedAt, $lastActivityAt, $now)) {
            $this->registry->forget($user->tenantId(), $user->identifier(), $session->getId());

            auth('staff')->logout();
            $session->invalidate();
            $session->regenerateToken();

            // FR-11: "expiry redirects to login preserving the return URL with a
            // 'session expired' notice". The notice and redirect belong to the
            // views step; what must be true NOW is that the request is no longer
            // authenticated, which is what the next middleware will see.
            return $next($request);
        }

        $session->put(SessionLifetimePolicy::LAST_ACTIVITY_AT, $now->toIso8601String());

        // Keeps the index's ordering honest, which is what FR-10's
        // oldest-first eviction and FR-12's "last active" both read.
        $this->registry->register($user->tenantId(), $user->identifier(), $session->getId(), $now);

        return $next($request);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
