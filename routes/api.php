<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SecuritySessionsController;
use App\Http\Controllers\Auth\TwoFactorAdminResetController;
use App\Http\Controllers\Auth\TwoFactorEnrolmentController;
use App\Http\Controllers\Foundation\IngressProbeController;
use App\Http\Controllers\Foundation\TenantSettingsController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Foundation routes (Doc 01)
|------------------------------------------------------------------------------
|
| WHAT THESE ARE, STATED PLAINLY SO THEY ARE NOT MISTAKEN FOR PRODUCT SURFACE.
|
| Doc 01 owns no user-facing features. It owns the CONTRACT that Docs 04–17 consume:
| resolution, state enforcement, settings, quotas. But AC-01, AC-02 and AC-08 are all
| stated in terms of HTTP — "via every public endpoint touching that table", "500
| requests with forged tenant_id", "every entry surface (staff login, API, webhook,
| widget, DLR)". Without endpoints those criteria cannot be executed at all, and an
| untestable acceptance criterion is an unmet one.
|
| So each of the five key-bearing surfaces gets a minimal ingress probe: it resolves a
| tenant, enforces FR-05, and reports what it resolved. The real Telegram webhook is
| Doc 05's, the real widget endpoint Doc 13's, the real DLR Doc 14's — each replaces
| its probe with a genuine handler on the SAME middleware stack, which is exactly the
| contract Doc 01 is supposed to hand them.
|
| The settings endpoints are real (FR-07's settings.read/write API) and stay.
|
*/

// NOTE: no `->prefix('api')` here. bootstrap/app.php's withRouting(api: …) already
// applies the `api` prefix to this file, and adding another produced routes at
// `api/api/v1/context` — which 404'd, and the 404 was indistinguishable from
// SEC-06's deliberate 404-on-failed-resolution. That is worth remembering: when
// every resolution failure is a 404 by design, a routing mistake wears the same
// mask, so check `route:list` before assuming the resolver is at fault.
Route::group([], function (): void {

    /*
     | ── Staff surface (FR-03a). Suspended blocks it entirely (FR-05, EC-03).
     |
     | THE SESSION MIDDLEWARE IS NOT OPTIONAL HERE, and its absence was a real
     | defect the browser suite caught. These routes authenticate via
     | `tenant:staff_session` — a SESSION — but Laravel's `api` group is
     | stateless: no cookie decryption, no session start. So a genuinely
     | signed-in agent's cookie meant nothing on this surface and every request
     | 404'd (Doc 01 SEC-06's deliberate 404-on-failed-resolution, wearing the
     | mask of a routing bug exactly as routes/api.php's own header warns).
     |
     | It went unnoticed because the PHP tests use actingAs(), which injects the
     | principal directly and therefore never exercises the cookie path at all.
     | The browser suite, with a real cookie jar, failed on its first request.
     |
     | The agent app is same-origin Inertia, so the staff API is deliberately
     | session-authenticated rather than token-authenticated; that is what T-02's
     | `staff` guard means. Bearer tokens get their own surface (FR-18/19) under
     | the `api` guard, which stays stateless.
     */
    Route::middleware([
        Illuminate\Cookie\Middleware\EncryptCookies::class,
        Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\Session\Middleware\StartSession::class,
        App\Http\Middleware\VerifyTenantHint::class,
        // FR-11/FR-12: BEFORE tenant resolution, deliberately. Expiry here
        // de-authenticates, so an expired session fails staff resolution with
        // the same 404 an unauthenticated request gets; placed after it, a
        // dead session would reach the controller with a tenant bound and no
        // principal. The touch half is FR-12's "last active".
        App\Http\Middleware\EnforceSessionLifetime::class,
        'tenant:staff_session',
        'tenant.state:staff',
        // I-5: the C-08 chain's first hop. After the session middleware (the
        // guard needs a started session to answer) and after tenant state (a
        // refused tenant's problem-details render in the DEFAULT locale — the
        // refusal must not vary by who asked).
        App\Http\Middleware\ApplyUserLocale::class,
    ])
        ->prefix('staff')
        ->group(function (): void {
            Route::get('context', [IngressProbeController::class, 'show'])->name('foundation.staff.context');

            // FR-12: the Security page's sessions table and its two actions.
            // Self-service (the caller's own sessions only, by construction) —
            // permission-exempt like logout; the mutations audit through the
            // revoker (auth.session.revoked per session) plus the always-fired
            // bulk entry (auth.session.revoked_others).
            Route::get('security/sessions', [SecuritySessionsController::class, 'index'])
                ->name('auth.sessions.index');
            Route::delete('security/sessions/{digest}', [SecuritySessionsController::class, 'destroy'])
                ->middleware('audit:auth.session.revoked')
                ->name('auth.sessions.revoke');
            Route::post('security/sessions/revoke-others', [SecuritySessionsController::class, 'destroyOthers'])
                ->middleware('audit:auth.session.revoked_others')
                ->name('auth.sessions.revoke_others');

            // FR-06/FR-07: self-service 2FA. begin is only reachable while 2FA
            // is off; every other mutation demands a current code (see the
            // controller). Permission-exempt like the sessions routes; each
            // mutation declares its audit action.
            Route::post('security/2fa', [TwoFactorEnrolmentController::class, 'store'])
                ->middleware('audit:auth.2fa.enrolment_started')
                ->name('auth.2fa.begin');
            Route::post('security/2fa/confirm', [TwoFactorEnrolmentController::class, 'confirm'])
                ->middleware('audit:auth.2fa.enabled')
                ->name('auth.2fa.confirm');
            Route::post('security/2fa/recovery-codes', [TwoFactorEnrolmentController::class, 'regenerateCodes'])
                ->middleware('audit:auth.2fa.codes_regenerated')
                ->name('auth.2fa.regenerate_codes');
            Route::delete('security/2fa', [TwoFactorEnrolmentController::class, 'destroy'])
                ->middleware('audit:auth.2fa.disabled')
                ->name('auth.2fa.disable');

            // FR-08: the admin reset — permission DECLARED from Doc 03's
            // catalog (admin.2fa.reset, high risk), enforced when its resolver
            // lands.
            Route::delete('admin/users/{userId}/two-factor', [TwoFactorAdminResetController::class, 'destroy'])
                ->middleware(['permission:admin.2fa.reset', 'audit:auth.2fa.reset_by_admin'])
                ->name('auth.2fa.admin_reset');

            // FR-07's settings.read/write API. DELETE is "reset to default" (§8),
            // which is a deletion of the override row rather than a write of the
            // default — see SettingsRepository::reset().
            // Permission keys from Doc 03's catalog (its §4 defines the names;
            // Doc 02's session added the mappings when the gate flipped blocking).
            // Until Doc 03's resolver lands these DECLARE rather than enforce for
            // staff sessions — see RequirePermission's docblock.
            Route::get('settings', [TenantSettingsController::class, 'index'])
                ->middleware('permission:settings.view')
                ->name('foundation.settings.index');
            Route::get('settings/{key}', [TenantSettingsController::class, 'show'])
                ->middleware('permission:settings.view')
                ->name('foundation.settings.show');
            Route::put('settings/{key}', [TenantSettingsController::class, 'update'])
                ->middleware(['permission:settings.edit', 'audit:tenant.setting_changed'])
                ->name('foundation.settings.update');
            Route::delete('settings/{key}', [TenantSettingsController::class, 'destroy'])
                ->middleware(['permission:settings.edit', 'audit:tenant.setting_changed'])
                ->name('foundation.settings.reset');
        });

    // ── Public API surface (FR-03b). Outbound-capable, so suspension blocks it.
    Route::middleware(['tenant:api_key', 'tenant.state:outbound'])
        ->prefix('v1')
        ->group(function (): void {
            Route::get('context', [IngressProbeController::class, 'show'])->name('foundation.api.context');
        });

    /*
     | ── The three INBOUND surfaces (FR-03c/d/e).
     |
     | All three use `tenant.state:inbound`, which permits a SUSPENDED tenant. That is
     | not an oversight — it is the subtlest requirement in FR-05 and the one most
     | likely to be "fixed" by someone who reads it as a bug. A suspended operator is
     | usually a payment problem, not a closed business, and US-02 says reactivation
     | must lose nothing. So a player's message is still accepted and queued while the
     | operator is suspended; what stops is anything going back out.
     */
    Route::middleware(['tenant:telegram_bot_key', 'tenant.state:inbound'])
        ->post('webhooks/telegram', [IngressProbeController::class, 'ingest'])
        ->name('foundation.webhook.telegram');

    Route::middleware(['tenant:widget_key', 'tenant.state:inbound'])
        ->post('widget/session', [IngressProbeController::class, 'ingest'])
        ->name('foundation.widget.session');

    Route::middleware(['tenant:sms_gateway_key', 'tenant.state:inbound'])
        ->post('webhooks/sms/dlr', [IngressProbeController::class, 'ingest'])
        ->name('foundation.webhook.sms_dlr');
});
