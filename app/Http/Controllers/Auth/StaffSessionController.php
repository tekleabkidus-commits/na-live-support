<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Lockout\LockoutManager;
use App\Domain\Auth\Login\FailureReason;
use App\Domain\Auth\Login\LoginRecorder;
use App\Domain\Auth\Login\LoginRefused;
use App\Domain\Auth\Login\StaffUserProvider;
use App\Domain\Auth\TwoFactor\PendingChallenge;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\SafeReturnUrl;
use App\Support\Auth\Sessions\SessionEstablisher;
use App\Support\Auth\Sessions\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

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
        private readonly SessionRegistry $sessions,
        private readonly SessionEstablisher $establisher,
        private readonly PendingChallenge $pending,
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

    /**
     * GET /login — the §8 login card (Doc 02 §8, §9, FR-11, AC-09; step 13).
     *
     * WHAT THE PROPS MAY AND MAY NOT CARRY. Everything here is either copy
     * (translated server-side in the request locale — C-08/N-03; the Vue layer
     * holds no literals) or state the CALLER's own session already proves:
     * whether a 2FA challenge is pending, whether the previous session expired
     * (FR-11's notice, flashed by EnforceSessionLifetime), and where to return
     * after sign-in. Nothing account-shaped is ever passed — the card renders
     * identically for every visitor, which is AC-01's non-enumeration property
     * extended to the screen itself.
     *
     * THE RETURN URL IS ATTACKER-POSITIONED and goes through SafeReturnUrl
     * (owner ruling: absolute, protocol-relative and backslash shapes all
     * refused). It is read, not pulled: a language-switch reload of this page
     * must not eat the pending redirect.
     *
     * THE 2FA STEP IS A STATE OF THIS CARD, not a route of its own (§8 lists
     * it among the card's states). A reload mid-challenge lands here and the
     * `challengePending` prop re-enters the code step — the pending record
     * itself stays server-side (PendingChallenge; SS-02).
     */
    public function create(Request $request): InertiaResponse
    {
        $pending = $this->pending->current($request);

        return Inertia::render('Auth/Login', [
            'challengePending' => $pending !== null,
            'sessionExpired' => (bool) $request->session()->get('auth.session_expired', false),
            'returnTo' => SafeReturnUrl::sanitize($request->session()->get('url.intended')),
            'locale' => app()->getLocale(),
            'copy' => [
                'title' => __('auth.login.title'),
                'emailLabel' => __('auth.login.email_label'),
                'passwordLabel' => __('auth.login.password_label'),
                'showPassword' => __('auth.login.show_password'),
                'hidePassword' => __('auth.login.hide_password'),
                'remember' => __('auth.login.remember'),
                'submit' => __('auth.login.submit'),
                'submitting' => __('auth.login.submitting'),
                'forgot' => __('auth.login.forgot'),
                'expiredNotice' => __('auth.login.expired_notice'),
                'errorFallback' => __('auth.login.error_fallback'),
                'languageLabel' => __('auth.login.language_label'),
                // Endonyms, identical in both language files: a person hunting
                // for their own language must find it written in that language.
                'langEnglish' => __('auth.login.lang_english'),
                'langAmharic' => __('auth.login.lang_amharic'),
                'continue' => __('auth.login.continue'),

                'challengeTitle' => __('auth.2fa.challenge_title'),
                'challengeIntro' => __('auth.2fa.challenge_intro'),
                'codeLabel' => __('auth.2fa.code_label'),
                'useRecovery' => __('auth.2fa.use_recovery'),
                'useTotp' => __('auth.2fa.use_totp'),
                'recoveryLabel' => __('auth.2fa.recovery_label'),
                'recoverySubmit' => __('auth.2fa.recovery_submit'),
                'recoveryUsed' => __('auth.2fa.recovery_used'),
                'lowCodesBanner' => __('auth.2fa.low_codes_banner'),
            ],
        ]);
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
        // Remember is deliberately NOT passed to attempt(): a remember cookie
        // is issued only once authentication is COMPLETE, and for a 2FA account
        // the password is half an authentication. The flag rides the pending
        // state and is honoured at establishment (FR-13 — the cookie extends
        // lifetime only, and never skips the challenge itself).
        $authenticated = auth('staff')->attempt(
            ['email' => $email, 'password' => $password],
            false,
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
        $remember = $request->boolean('remember');

        $response = app(Pipeline::class)
            ->send($request)
            ->through($this->pipesAfterCredentialCheck())
            ->then(fn (Request $request): JsonResponse|RedirectResponse => $this->completeOrChallenge($request, $user, $remember));

        // The pipeline's return type is mixed by construction; every pipe either
        // passes the request through or throws LoginRefused, so the only value
        // that can arrive here is the closure's.
        return $response instanceof JsonResponse || $response instanceof RedirectResponse
            ? $response
            : $this->completeOrChallenge($request, $user, $remember);
    }

    /**
     * §10's fork: 2FA enabled → challenge; otherwise a session, now.
     */
    private function completeOrChallenge(Request $request, User $user, bool $remember): JsonResponse|RedirectResponse
    {
        if ($user->getAttribute('two_factor_confirmed_at') !== null) {
            // The password alone is HALF an authentication for this account
            // (FR-07, SS-02). attempt() authenticated the guard, so both traces
            // of that must go before the response: the in-memory user and the
            // session's auth key. forgetUser, never logout() — logout cycles
            // the remember token, a user save this request must not perform.
            $guard = auth('staff');
            if ($guard instanceof SessionGuard) {
                $request->session()->forget($guard->getName());
                $guard->forgetUser();
            }

            $this->pending->stash($request, $user, $remember);

            // 401, honestly: the caller is not authenticated. It also keeps the
            // route's `audit:auth.login.succeeded` declaration truthful — that
            // entry is written when a session exists, and DeclareAuditAction
            // only demands it of 2xx responses. The login card acts on this
            // status to enter its 2FA step (step 13).
            return new JsonResponse(['status' => 'two_factor_required'], 401);
        }

        if ($remember) {
            // Full authentication just completed with no challenge owed, so the
            // remember cookie withheld from attempt() is issued here (FR-13).
            auth('staff')->login($user, true);
        }

        return $this->establisher->establish($request, $user);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = auth('staff')->user();

        $this->recorder->loggedOut($user, $request);

        // Drop the index entry as well as the session: a logged-out session that
        // lingered in the index would show on the devices screen as active.
        $this->sessions->forget($user->tenantId(), $user->identifier(), $request->session()->getId());

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
}
