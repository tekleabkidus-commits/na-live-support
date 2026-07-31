<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\Sessions\TenantHint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authority half of the tenant hint (Doc 02 T-04; owner ruling Q7).
 *
 * TenantHint decides WHERE to look for the session. This decides whether what
 * was found may be used. The split matters: a signature proves the cookie was
 * issued by this application, and proves nothing about whether it belongs to the
 * session it arrived with. Anyone who has ever logged into ANY tenant holds a
 * validly-signed hint for that tenant, so signature-only checking would let a
 * user replay their own hint alongside a session id obtained some other way.
 *
 * So the rule the ruling requires: if the loaded session's user does not belong
 * to the hinted tenant, the session is DISCARDED — treated as no session at all,
 * not reinterpreted as belonging to the hint. Reinterpreting is the dangerous
 * failure, because it is the one that silently succeeds.
 *
 * Runs after the session is loaded and before anything reads the authenticated
 * user, which is why it sits in the `web` group rather than on individual
 * routes: a route that forgot to declare it would be a route where the check
 * does not happen, and that is exactly the kind of omission this platform makes
 * structural elsewhere.
 */
final class VerifyTenantHint
{
    public function __construct(private readonly TenantHint $hint) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hinted = $this->hint->current();
        $user = $request->user('staff');

        if ($user instanceof User && $hinted !== null) {
            // SCOPE, stated precisely because it is narrower than it first looks.
            // This rejects a hint that is PRESENT and WRONG — the owner's ruling:
            // "a tampered or mismatched tenant-hint cookie is treated as no
            // session". It deliberately does NOT reject an ABSENT hint.
            //
            // An absent hint is not an attack, it is a session established
            // before this mechanism existed, or a principal resolved by
            // something other than the session cookie. Rejecting those would
            // turn a missing cookie into a logout, and — since the hint is what
            // SELECTS the storage keyspace — a session with no hint was read
            // from the guest keyspace and carries no tenant-prefixed data to
            // leak in the first place. The forgery this defends against is
            // pointing a hint at ANOTHER tenant, and that always has a value.
            if ($hinted !== $user->tenantId()) {
                $this->discard($request);

                logger()->warning('Session discarded: tenant hint does not match its user', [
                    // Ids only — no email, no cookie value (SEC-08).
                    'user_id' => $user->identifier(),
                    'hinted_tenant' => $hinted,
                ]);
            }
        }

        return $next($request);
    }

    /**
     * Drop the principal WITHOUT touching the user record.
     *
     * `logout()` looks like the obvious call and is the wrong one, for two
     * reasons discovered by the positive control failing with a 500:
     *
     *   1. It cycles the remember-me token, which SAVES the user — and this runs
     *      with no tenant bound (the whole point is that the hint could not be
     *      trusted to bind one), so the write is refused by the cross-tenant
     *      guard. A defence that throws is a defence that becomes a 500 page.
     *   2. Worse than the error: cycling a token on an attacker-supplied cookie
     *      is a denial of service. Forge a hint, and a legitimate user's
     *      remember-me is invalidated on every device. The forgery must cost the
     *      victim nothing.
     *
     * forgetUser() drops the resolved principal for this request only, which is
     * exactly "treat it as no session" and touches no state at all.
     */
    private function discard(Request $request): void
    {
        $guard = auth('staff');

        if ($guard instanceof \Illuminate\Auth\SessionGuard) {
            $guard->forgetUser();
        }

        if ($request->hasSession()) {
            // The session data itself is abandoned rather than rewritten: a
            // forged hint must not let an attacker destroy a real session.
            $request->session()->forget('login_staff_'.sha1(\Illuminate\Auth\SessionGuard::class));
        }
    }
}
