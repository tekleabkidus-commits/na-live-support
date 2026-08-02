<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use App\Domain\Auth\Lockout\LockoutManager;
use App\Domain\Auth\Login\LoginRecorder;
use App\Models\User;
use App\Support\Auth\Devices\DeviceRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Everything that happens the moment a principal becomes a SESSION
 * (Doc 02 SEC-04, FR-10, FR-11, FR-04/T-03, T-04/Q7).
 *
 * Extracted from StaffSessionController in the 2FA step, because two doors now
 * lead here: the password-only login and the 2FA challenge. The sequence is
 * identical from either door and MUST stay identical — SEC-04's regeneration,
 * FR-11's two clocks, FR-10's cap, the device record and the tenant hint are
 * one ritual, and a second hand-rolled copy in the challenge controller would
 * be the copy that forgets a step. Called only after the caller has fully
 * authenticated the user on the staff guard (including any 2FA the account
 * requires) — reaching this line IS the assertion that authentication is done.
 */
final class SessionEstablisher
{
    public function __construct(
        private readonly LoginRecorder $recorder,
        private readonly LockoutManager $lockout,
        private readonly TenantHint $hint,
        private readonly SessionRegistry $sessions,
        private readonly SessionRevoker $revoker,
        private readonly SessionLifetimePolicy $lifetime,
        private readonly DeviceRegistrar $devices,
        private readonly SessionMetadataStore $sessionMetadata,
    ) {}

    /**
     * @param  array<string, mixed>  $extraPayload  merged into the JSON body —
     *                                              FR-07's recovery-code count rides here
     */
    public function establish(Request $request, User $user, array $extraPayload = []): JsonResponse|RedirectResponse
    {
        // T-04/Q7: point storage at this user's tenant BEFORE regenerating, so
        // the new session id is written under `t:{tenant}:` rather than into the
        // guest keyspace. The guest session is abandoned, not migrated.
        $tenantId = $user->tenantId();
        $this->hint->useForThisRequest($tenantId);

        // SEC-04: regenerate at the privilege boundary. From the password door
        // this is the first boundary; from the challenge door it is the SECOND
        // (post-2FA) — the pending-challenge session id dies here, so an id
        // fixed before or during the challenge cannot become an authenticated
        // one.
        //
        // REDUNDANT WITH THE GUARD, DELIBERATELY. Laravel's SessionGuard::login()
        // already migrates the session, so removing this line does not reopen
        // the hole — falsification proved exactly that (the browser test stayed
        // green). It is kept because the protection then depends on a framework
        // internal rather than on anything this document controls; when a
        // non-migrating login was SIMULATED the test failed with "the planted
        // id is still live". Belt and braces, with the test watching both.
        $request->session()->regenerate();

        // FR-03: a success ends the current failure window — and from the
        // challenge door this is deliberately AFTER the TOTP, so a voided
        // challenge's contribution to lockout survives a later good password.
        $this->lockout->recordSuccess($user);

        // FR-11's two clocks start here. `established_at` is stamped ONCE and
        // never refreshed — that is what makes the absolute limit absolute.
        $now = CarbonImmutable::now();
        $request->session()->put(SessionLifetimePolicy::ESTABLISHED_AT, $now->toIso8601String());
        $request->session()->put(SessionLifetimePolicy::LAST_ACTIVITY_AT, $now->toIso8601String());

        // FR-10: index the new session, then evict the oldest beyond the cap.
        // Registration comes first so the cap counts the session that just
        // authenticated — otherwise a 6th login would evict one of the first
        // five and leave six live.
        $sessionId = $request->session()->getId();
        $this->sessions->register($tenantId, $user->identifier(), $sessionId, $now);

        $evicted = $this->sessions->enforceCap(
            $tenantId,
            $user->identifier(),
            $this->lifetime->maxConcurrent(),
            $sessionId,
        );

        foreach ($evicted as $evictedSessionId) {
            $this->revoker->revoke(
                $tenantId,
                $user->identifier(),
                $evictedSessionId,
                reason: 'concurrent_session_cap',
            );
        }

        // FR-04/T-03: the device upsert — one row per (user, fingerprint),
        // city resolved in-process and STORED (T-08, FS-04; ADR 0006). After
        // the cap enforcement so an evicted session cannot carry a fresher
        // device stamp than the login that evicted it.
        ['device' => $device, 'new' => $newDevice] = $this->devices->record($user, $request, $now);

        // FR-12: the facts the Security page shows for THIS session. The city
        // may be null — that IS the FS-04 "location unavailable" state, stored
        // as such rather than papered over.
        $this->sessionMetadata->put($tenantId, $sessionId, [
            'device_id' => $device->identifier(),
            'device_label' => is_string($device->label) ? $device->label : '',
            'ip' => $request->ip(),
            'city' => is_string($device->last_city) ? $device->last_city : null,
            'country_code' => is_string($device->last_country_code) ? $device->last_country_code : null,
            'signed_in_at' => $now->toIso8601String(),
        ]);

        // T-03's notice itself is deferred with the notification pipeline
        // (Docs 07/14); newDevice is recorded so the trail already says when a
        // machine was first seen.
        $this->recorder->succeeded($user, $request, deviceId: $device->identifier(), newDevice: $newDevice);

        $response = $request->expectsJson()
            // No tenant id, no workspace name, no candidate count: a successful
            // response must not enumerate either (AC-01).
            ? new JsonResponse(['status' => 'signed_in'] + $extraPayload)
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
