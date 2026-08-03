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

            // forgetUser, NOT logout() — the same fix S6 applied to
            // VerifyTenantHint::discard, rediscovered here the moment this
            // middleware was first WIRED: logout() cycles the remember token,
            // which SAVES the user with no tenant bound and turns every expiry
            // into a 500. Not cycling is also what FR-13 specifies — remember-me
            // lives to the ABSOLUTE limit by its own cookie lifetime, so an
            // idle expiry must leave it able to re-authenticate.
            $guard = auth('staff');
            if ($guard instanceof \Illuminate\Auth\SessionGuard) {
                $guard->forgetUser();
            }
            $session->invalidate();
            $session->regenerateToken();

            // FR-11: "expiry redirects to login preserving the return URL with
            // a 'session expired' notice". The redirect and the return URL are
            // the framework's: the now-unauthenticated request falls through to
            // `auth:staff`, whose guest redirect targets /login and stores the
            // intended URL in the session. The NOTICE is this flag, written
            // into the fresh post-invalidate session and read by the login
            // card's controller. Not flash() — a language-switch reload of the
            // login card must not eat the notice; login itself destroys the
            // session (SEC-04) and the flag with it.
            $session->put('auth.session_expired', true);

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
