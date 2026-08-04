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
        // FR-15 max-age: after locale so the confinement notice renders in the
        // user's language; the change endpoint itself is on its allowlist.
        App\Http\Middleware\EnforcePasswordExpiry::class,
        // FR-09 (closed at S19): required-role 2FA enforcement — the staff
        // DATA surface locks with the screens, or the lockout is decorative.
        App\Http\Middleware\EnforceTwoFactorForRole::class,
    ])
        ->prefix('staff')
        ->group(function (): void {
            Route::get('context', [IngressProbeController::class, 'show'])->name('foundation.staff.context');

            // FR-12: the Security page's sessions table and its two actions.
            // Self-service (the caller's own sessions only, by construction) —
            // permission-exempt like logout; the mutations audit through the
            // revoker (auth.session.revoked per session) plus the always-fired
            // bulk entry (auth.session.revoked_others).
            // §8's timeout warning (step 13): the deadline heartbeat. A GET
            // that mutates nothing beyond what EVERY staff request already
            // does — EnforceSessionLifetime, ahead of it in this stack, rolls
            // the idle clock; the absolute clock is untouchable by design (see
            // the controller). Self-service, permission-exempt like the
            // sessions routes; the sentinel also uses it as its known-good
            // probe to tell a dead session from a genuine 404 (C-10 makes the
            // two look alike on purpose — interpretation A-2 in ANSWERS).
            Route::get('session/deadline', [App\Http\Controllers\Auth\SessionDeadlineController::class, 'show'])
                ->name('auth.session.deadline');

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

            // FR-14/FR-15: the authenticated self-change. Proves the CURRENT
            // password before accepting a new one (a session is a weaker
            // credential than the password — see the controller); revokes all
            // OTHER sessions on success. Self-service, permission-exempt like
            // the sessions routes. On EnforcePasswordExpiry's allowlist so an
            // expired password can still be changed.
            Route::put('security/password', [App\Http\Controllers\Auth\PasswordChangeController::class, 'update'])
                ->middleware('audit:auth.password.changed')
                ->name('auth.password.change');

            // FR-18: personal access tokens, self-service. Permission-exempt
            // BY RULING after checking Doc 03 §4.1's catalog end to end: it
            // contains no personal-token key (apikeys.machine.manage governs
            // machine tokens only, and is enforced on those routes below).
            // Same self-scoped-by-construction shape as the sessions routes —
            // every query starts from the caller's own tokens() relation.
            Route::get('security/tokens', [App\Http\Controllers\Auth\PersonalTokensController::class, 'index'])
                ->name('auth.tokens.index');
            Route::post('security/tokens', [App\Http\Controllers\Auth\PersonalTokensController::class, 'store'])
                ->middleware('audit:auth.token.created')
                ->name('auth.tokens.store');
            Route::delete('security/tokens/{tokenId}', [App\Http\Controllers\Auth\PersonalTokensController::class, 'destroy'])
                ->middleware('audit:auth.token.revoked')
                ->name('auth.tokens.revoke');

            // FR-19: machine tokens — admin-created, service-account-bound,
            // rotated with an overlap window. apikeys.machine.manage is
            // Doc 03 §4.1's own key (high risk): declared here, enforced for
            // token principals NOW (T-05 branch), for staff when the resolver
            // lands — the same posture as the settings routes below.
            Route::get('admin/machine-tokens', [App\Http\Controllers\Auth\MachineTokensController::class, 'index'])
                ->middleware('permission:apikeys.machine.manage')
                ->name('auth.machine_tokens.index');
            Route::post('admin/machine-tokens', [App\Http\Controllers\Auth\MachineTokensController::class, 'store'])
                ->middleware(['permission:apikeys.machine.manage', 'audit:auth.token.created'])
                ->name('auth.machine_tokens.store');
            Route::post('admin/machine-tokens/{tokenId}/rotate', [App\Http\Controllers\Auth\MachineTokensController::class, 'rotate'])
                ->middleware(['permission:apikeys.machine.manage', 'audit:auth.token.created'])
                ->name('auth.machine_tokens.rotate');
            Route::delete('admin/machine-tokens/{tokenId}', [App\Http\Controllers\Auth\MachineTokensController::class, 'destroy'])
                ->middleware(['permission:apikeys.machine.manage', 'audit:auth.token.revoked'])
                ->name('auth.machine_tokens.revoke');

            // FR-08: the admin reset — permission DECLARED from Doc 03's
            // catalog (admin.2fa.reset, high risk), enforced when its resolver
            // lands.
            Route::delete('admin/users/{userId}/two-factor', [TwoFactorAdminResetController::class, 'destroy'])
                ->middleware(['permission:admin.2fa.reset', 'audit:auth.2fa.reset_by_admin'])
                ->name('auth.2fa.admin_reset');

            // S19 step 7 — the Doc 02 admin surfaces that waited for real
            // enforcement (see AdminUsersController's docblock).
            Route::post('admin/users/{userId}/unlock', [App\Http\Controllers\Auth\AdminUsersController::class, 'unlock'])
                ->middleware(['permission:admin.users.manage', 'audit:auth.lockout.unlocked'])
                ->name('auth.admin_users.unlock');
            Route::post('admin/users/{userId}/disable', [App\Http\Controllers\Auth\AdminUsersController::class, 'disable'])
                ->middleware(['permission:admin.users.disable', 'audit:auth.user.disabled'])
                ->name('auth.admin_users.disable');

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
     | ── The BEARER surface (Doc 02 FR-18/19/20, T-02, T-05, T-06).
     |
     | Stateless by construction: no cookie middleware, no session, therefore
     | no CSRF — which is T-06's "Bearer API CSRF-exempt" as an absence rather
     | than an exemption list. Order is T-02's: tenant-resolve (the bearer
     | binder, through the resolve_api_token door) → guard (Sanctum, under the
     | already-bound RLS) → tenant-state (outbound: a suspended operator's
     | integrations stop sending). Permission enforcement is per-route via
     | `permission:<key>`, whose token branch is the T-05 intersection.
     |
     | PROBE-ONLY IN PRODUCTION (step-9 ruling Q-A): no document makes any
     | product API token-accessible yet, so the only real route is the context
     | probe. Docs 04/06 mount the first real endpoints on THIS stack; AC-05's
     | permission-gated routes mount on it in tests today.
     */
    Route::middleware(['tenant.bearer', 'auth:api', 'tenant.state:outbound'])
        ->prefix('v1')
        ->group(function (): void {
            Route::get('token/context', [IngressProbeController::class, 'show'])->name('auth.token.context');
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
    /*
     | Since Doc 02 step 11 both webhook probes demand the SECOND credential
     | (FR-23/FR-24) behind the route key, on the same probe handlers — the
     | auth mechanics are Doc 02's; Docs 05/14 replace the HANDLERS and own
     | the probes' audit-exemption lines. Order: binder (key id + RLS) →
     | per-key throttle (cheapest first, FR-25/FR-03 pattern) → second
     | credential → state. Nothing here is priority-listed, so this declared
     | order is the executed order (MiddlewareOrderTest asserts it).
     | A key with no seeded webhook credential refuses 403 BY DESIGN —
     | staging included (step-11 ruling, decision 6).
     */
    Route::middleware([
        'tenant:telegram_bot_key',
        'webhook.throttle',
        'webhook.auth:secret_header,X-Telegram-Bot-Api-Secret-Token',
        'tenant.state:inbound',
    ])
        ->post('webhooks/telegram', [IngressProbeController::class, 'ingest'])
        ->name('foundation.webhook.telegram');

    /*
     | ── The WIDGET surface (Doc 02 FR-21/FR-22, T-02, T-06, BR-06, AC-06).
     |
     | Supersedes Doc 01's widget ingress probe with the real session issuer,
     | on the same trust model: `tenant:widget_key` resolves the tenant from
     | the widget key, `tenant.state:inbound` keeps a suspended operator's
     | visitors alive (their chat identity is inbound state; what suspension
     | stops is outbound sends).
     |
     | Order is T-02's: tenant-resolve → guard → tenant-state, with WidgetCors
     | between binder and guard — INSIDE the binder because its response-side
     | allowlist stamp reads the widgets table under the bound RLS scope,
     | OUTSIDE the guard so the guard's own 401 reaches an allowed page
     | CORS-readable (the mismatch→re-POST /session recovery depends on it).
     | The guard middleware is widget.auth (AuthenticateWidgetVisitor), NOT
     | auth:widget — Laravel's Authenticate is priority-listed and the sorter
     | would hoist it over the binder (see the middleware's docblock for the
     | full sorter story); declared order here IS executed order, and
     | MiddlewareOrderTest asserts both that and Authenticate's absence.
     |
     | BR-06 structurally: separate route group, separate guard whose
     | principal is not a User, separate cookie scope (Path=/api/widget).
     | No cookie/session middleware here — the FR-22 cookie is set raw on the
     | response and read raw from the request.
     |
     | Like the bearer surface, PROBE-ONLY beyond session issuance: the
     | context probe and the Reverb auth door are the whole authenticated
     | subset until Docs 04/13 mount real endpoints on THIS stack.
     */
    Route::prefix('widget/v1')->group(function (): void {
        // Preflights carry no key and can never resolve a tenant; they land
        // here, on WidgetCors alone. See WidgetPreflightController.
        Route::options('{any}', App\Http\Controllers\Widget\WidgetPreflightController::class)
            ->where('any', '.*')
            ->middleware(App\Http\Middleware\WidgetCors::class)
            ->name('auth.widget.preflight');

        Route::middleware(['tenant:widget_key', App\Http\Middleware\WidgetCors::class, 'tenant.state:inbound'])
            ->post('session', [App\Http\Controllers\Widget\WidgetSessionController::class, 'store'])
            ->name('auth.widget.session');

        Route::middleware(['tenant:widget_key', App\Http\Middleware\WidgetCors::class, App\Http\Middleware\AuthenticateWidgetVisitor::class, 'tenant.state:inbound'])
            ->group(function (): void {
                Route::get('context', [IngressProbeController::class, 'show'])
                    ->name('auth.widget.context');

                // FR-21's "that visitor's own Reverb channels": the widget
                // surface's OWN broadcasting-auth door. The visitor channel in
                // routes/channels.php names the widget guard; the staff door
                // (Doc 07's, when it builds one) will never accept a visitor
                // token any more than this one accepts a staff session.
                Route::post('broadcasting/auth', [Illuminate\Broadcasting\BroadcastController::class, 'authenticate'])
                    ->name('auth.widget.broadcast');
            });
    });

    Route::middleware([
        'tenant:sms_gateway_key',
        'webhook.throttle',
        'webhook.auth:body_hmac',
        'tenant.state:inbound',
    ])
        ->post('webhooks/sms/dlr', [IngressProbeController::class, 'ingest'])
        ->name('foundation.webhook.sms_dlr');
});
