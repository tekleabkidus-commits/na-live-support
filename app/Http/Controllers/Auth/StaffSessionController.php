<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Lockout\LockoutManager;
use App\Domain\Auth\Login\FailureReason;
use App\Domain\Auth\Login\LoginRecorder;
use App\Domain\Auth\Login\LoginRefused;
use App\Domain\Auth\Login\StaffUserProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Sessions\TenantHint;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;

/**
 * The staff login and logout surface (Doc 02 FR-01, FR-02, FR-04, FR-05, SEC-04).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A CONTROLLER RATHER THAN FORTIFY'S OWN.
 *
 * T-01 says Fortify drives the auth flows, and it does: the credential check runs
 * through the `staff` guard and its provider, the 2FA challenge and password
 * reset flows are Fortify's, and Fortify's events map to §4.8. What Fortify's
 * stock controller cannot express is this document's ORDERING requirements —
 * FR-05's blocked-principal screens must run after credential verification but
 * before a session is established, and SEC-04 requires session-id regeneration
 * at that exact boundary. Fortify's pipeline is configurable but its response
 * contract is not, and the pieces this needs to sit between are all in the
 * response half.
 *
 * So the pipeline is assembled explicitly here, in the order Doc 02 §10's
 * diagram specifies, with each stage a named class that can be tested alone.
 * The stages grow as later steps land — the lockout state machine, the 2FA
 * challenge, the session cap — and this docblock is the map.
 *
 * WHAT IS ALREADY GUARANTEED BEFORE ANY LINE OF THIS RUNS: the request arrived
 * over TLS (SEC-06 middleware), it survived the per-IP throttle before any
 * hashing happened (SEC-05, route middleware), and the caller is not already
 * authenticated (`guest:staff`).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class StaffSessionController extends Controller
{
    public function __construct(
        private readonly LoginRecorder $recorder,
        private readonly LockoutManager $lockout,
        private readonly TenantHint $hint,
    ) {}

    /**
     * The staff guard's provider, typed.
     *
     * Reached through the guard rather than the container so it is the SAME
     * instance the attempt just used — a freshly resolved provider would carry
     * no candidates, and the lockout would silently count nothing.
     */
    private function provider(): StaffUserProvider
    {
        $guard = auth('staff');
        $provider = $guard instanceof SessionGuard ? $guard->getProvider() : null;

        if (! $provider instanceof StaffUserProvider) {
            throw new \LogicException(
                'The staff guard is not backed by StaffUserProvider. Login cannot enforce lockout or the '
                .'AC-01 equalisation without it — check config/auth.php providers.users.driver.',
            );
        }

        return $provider;
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // validate() returns mixed; the rules above guarantee both keys are
        // present strings, but the type system cannot see that, and reading
        // through a narrowing array keeps the guarantee explicit.
        $credentials = is_array($validated) ? $validated : [];
        $email = is_string($credentials['email'] ?? null) ? $credentials['email'] : '';
        $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';

        // The provider does the pre-tenant lookup, the timing equalisation, and
        // the candidate disambiguation; it binds the tenant on success. It
        // deliberately does NOT decide about disabled users, suspended tenants or
        // lockout — those have distinct outcomes and audit entries, and live in
        // the pipeline below.
        $authenticated = auth('staff')->attempt(
            ['email' => $email, 'password' => $password],
            // FR-13: remember-me extends the COOKIE only. It never bypasses a
            // later 2FA challenge on a new device — that binding is the 2FA
            // step's, and this flag does not reach it.
            $request->boolean('remember'),
        );

        if (! $authenticated) {
            // FR-03: count this failure against every candidate the address
            // matched (owner ruling — see LockoutManager). An address matching
            // nothing produces an empty list and no writes, which is correct:
            // there is no account to protect and nothing to audit.
            $provider = $this->provider();
            $candidates = $provider->lastCandidates();
            $ambiguous = $provider->lastAttemptWasAmbiguous();
            $this->lockout->recordFailedAttempt($candidates, CarbonImmutable::now());

            if ($candidates === []) {
                $this->recorder->failedForUnknownAccount($request);
            } else {
                foreach ($candidates as $candidate) {
                    // Owner ruling R-2: an ambiguous refusal is recorded as such
                    // in the event and audit trail. The RESPONSE is unchanged —
                    // LoginRefused renders the generic message for every
                    // credential failure (FR-02/AC-01).
                    $reason = match (true) {
                        $ambiguous => FailureReason::AmbiguousTenant,
                        $candidate->isLockedAt(CarbonImmutable::now()) => FailureReason::Locked,
                        default => FailureReason::BadCredentials,
                    };

                    $this->recorder->failedForKnownAccount($candidate, $reason, $request);
                }
            }

            // FR-02/AC-01: ONE generic message for every credential failure —
            // wrong password, no such address, address in two tenants with the
            // same password. The bodies must be byte-identical, which is why
            // this is a single throw site rather than a per-case message.
            throw new LoginRefused(FailureReason::BadCredentials);
        }

        /** @var User $user */
        $user = auth('staff')->user();

        $response = app(Pipeline::class)
            ->send($request)
            ->through($this->pipesAfterCredentialCheck())
            ->then(fn (Request $request): JsonResponse|RedirectResponse => $this->establishSession($request, $user));

        // The pipeline's return type is mixed by construction; every pipe either
        // passes the request through or throws LoginRefused, so the only value
        // that can arrive here is the closure's.
        return $response instanceof JsonResponse || $response instanceof RedirectResponse
            ? $response
            : $this->establishSession($request, $user);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = auth('staff')->user();

        $this->recorder->loggedOut($user, $request);

        auth('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? new JsonResponse(['status' => 'signed_out'])
            : redirect('/');
    }

    /**
     * The stages between "the password was right" and "a session exists".
     *
     * Order matters and is Doc 02 §10's. Later steps insert their stages here:
     * the lockout state machine records the successful attempt and clears the
     * counter, the 2FA challenge interrupts before a full-privilege session, and
     * the session cap evicts the oldest session.
     *
     * @return list<class-string>
     */
    private function pipesAfterCredentialCheck(): array
    {
        return [
            \App\Domain\Auth\Login\Pipes\RefuseBlockedPrincipal::class,
        ];
    }

    private function establishSession(Request $request, User $user): JsonResponse|RedirectResponse
    {
        // T-04/Q7: point storage at this user's tenant BEFORE regenerating, so
        // the new session id is written under `t:{tenant}:` rather than into the
        // guest keyspace. The guest session is abandoned, not migrated.
        $tenantId = $user->tenantId();
        $this->hint->useForThisRequest($tenantId);

        // SEC-04, first of two privilege boundaries: regenerate the session id
        // the moment the password is accepted, so a fixed pre-login id cannot
        // become an authenticated one. The second boundary (post-2FA) is added
        // by the 2FA step.
        $request->session()->regenerate();

        // FR-03: a success ends the current failure window. Done here rather
        // than in the provider because it is a state TRANSITION, and those
        // belong to the manager that can audit them.
        $this->lockout->recordSuccess($user);

        // FR-04's device record and T-03's new-device notice arrive with the
        // sessions step; until then the recorder is told plainly that neither
        // exists yet rather than being fed a placeholder that looks real.
        $this->recorder->succeeded($user, $request, deviceId: '', newDevice: false);

        $response = $request->expectsJson()
            // No tenant id, no workspace name, no candidate count: a successful
            // response must not enumerate either (AC-01).
            ? new JsonResponse(['status' => 'signed_in'])
            : redirect()->intended('/');

        // The hint travels with the same flags as the session cookie: HttpOnly
        // so script cannot read it, Secure because SEC-06 refuses cleartext
        // anyway, SameSite=Lax to match FR-10.
        // Config values cross a type boundary (config() returns mixed), so each
        // is narrowed with the same defaults config/session.php declares rather
        // than cast — a malformed value must not silently widen the cookie's
        // scope.
        $lifetime = config('session.lifetime');
        $path = config('session.path');
        $domain = config('session.domain');
        $sameSite = config('session.same_site');

        return $response->withCookie(cookie(
            name: TenantHint::COOKIE,
            value: $this->hint->sign($tenantId),
            minutes: is_numeric($lifetime) ? (int) $lifetime : 120,
            path: is_string($path) && $path !== '' ? $path : '/',
            domain: is_string($domain) ? $domain : null,
            secure: true,
            httpOnly: true,
            sameSite: is_string($sameSite) && $sameSite !== '' ? $sameSite : 'lax',
        ));
    }
}
