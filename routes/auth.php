<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SecurityPageController;
use App\Http\Controllers\Auth\StaffSessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Authentication surface (Doc 02; required from routes/web.php)
|------------------------------------------------------------------------------
|
| EVERY auth route is registered here BY HAND — Fortify auto-registration is
| permanently off (FortifyServiceProvider). The rule, inherited from Doc 01 and
| made policy by Doc 02: a route ships in the same step as its proven flow,
| carrying its full middleware chain in T-02's order
|
|     tenant-resolve → guard → tenant-state → 2FA-enforcement → RBAC
|
| plus its `audit:<action>` declaration (mutating routes) and its
| `permission:<key>` mapping or ci/permission-exempt-routes.php entry. The
| RouteRegistrationTest mirrors and the CI gates both enforce this shape.
|
| This file is EMPTY at the skeleton step by design: no auth flow is proven yet,
| so no auth route exists yet. Each Doc 02 step appends its own surface:
|
|   login step      POST /login, POST /logout
|   session step    devices list/revoke
|   2FA step        two-factor challenge / enrolment / recovery / admin reset
|   password step   forgot / reset / invited set-password
|   token step      personal + machine token management
|
| A visitor hitting an unregistered path gets a 404 — which is honest. The
| alternative (a live login form whose backing flow is unbuilt) is the Doc 01
| defect this file exists to prevent.
|
*/

/*
|------------------------------------------------------------------------------
| Login (Doc 02 FR-01, FR-02, FR-04, FR-05, SEC-05)
|------------------------------------------------------------------------------
|
| MIDDLEWARE ORDER IS THE SPECIFICATION, not a style choice (T-02, SEC-05):
|
|   throttle  runs FIRST, before any expensive work. SEC-05's pattern and FR-03's
|             independent per-IP limit both live here — an attacker must not be
|             able to make the server spend ~100 ms of argon2id per request just
|             by sending requests. This is why the limiter is middleware rather
|             than a check inside the controller.
|   guest     a signed-in user hitting /login is redirected, not re-authenticated.
|
| There is deliberately NO `tenant:` middleware on these routes: resolving the
| tenant is what logging in DOES (Doc 01 FR-03a resolves a tenant FROM the
| authenticated user, which does not exist yet). The tenant is bound inside the
| pipeline by StaffUserProvider the moment a candidate's password verifies.
|
| Both routes are exempt from the permission registry — see
| ci/permission-exempt-routes.php: authentication is what establishes the
| principal a permission could be checked against, so requiring one here is
| circular.
|
*/

Route::middleware(['guest:staff', 'throttle:auth-ip'])->group(function (): void {
    Route::post('login', [StaffSessionController::class, 'store'])
        ->middleware('audit:auth.login.succeeded')
        ->name('auth.login');

    // FR-07: the challenge is the login's second half — same guest posture
    // (no principal exists until the code verifies, SS-02), same per-IP
    // throttle (code guessing is capped exactly like password guessing), and
    // the same audit declaration, honoured when a session is established.
    Route::post('login/two-factor', [TwoFactorChallengeController::class, 'store'])
        ->middleware('audit:auth.login.succeeded')
        ->name('auth.2fa.challenge');
});

/*
|------------------------------------------------------------------------------
| Password lifecycle (Doc 02 FR-16, FR-17, SEC-01, SEC-02, EC-04, EC-11)
|------------------------------------------------------------------------------
|
| All guest surfaces, all behind the same per-IP throttle as login (SEC-05:
| token guessing and address probing are capped exactly like password
| guessing). No tenant middleware for the same reason as /login: the tenant is
| what the TOKEN resolves (via the resolve_auth_action_token door) or what the
| candidate lookup discovers per account.
|
| AUDIT SHAPE, decided by what a 2xx PROVES: the two apply routes (reset store,
| invitation accept) mutate on every success and DECLARE their actions; the two
| request routes (forgot, resend) answer 200 identically whether or not any
| account matched (SEC-01), so a 2xx proves nothing about mutation and they are
| audit-EXEMPT with reasons — the per-candidate trail is still written by
| PasswordLifecycleRecorder whenever a token was actually minted.
|
| GETs render forms; they mutate nothing and declare nothing.
|
*/

Route::middleware(['guest:staff', 'throttle:auth-ip'])->group(function (): void {
    Route::get('forgot-password', [PasswordResetController::class, 'showRequestForm'])
        ->name('auth.password.forgot.form');
    Route::post('forgot-password', [PasswordResetController::class, 'request'])
        ->name('auth.password.forgot');

    Route::get('reset-password', [PasswordResetController::class, 'show'])
        ->name('auth.password.reset.form');
    Route::post('reset-password', [PasswordResetController::class, 'store'])
        ->middleware('audit:auth.password.reset_completed')
        ->name('auth.password.reset');

    Route::get('invitation', [InvitationController::class, 'show'])
        ->name('auth.invitation.form');
    Route::post('invitation', [InvitationController::class, 'store'])
        ->middleware('audit:auth.invite.accepted')
        ->name('auth.invitation.accept');
    Route::post('invitation/resend', [InvitationController::class, 'resend'])
        ->name('auth.invitation.resend');
});

Route::middleware('auth:staff')->group(function (): void {
    Route::post('logout', [StaffSessionController::class, 'destroy'])
        ->middleware('audit:auth.logout')
        ->name('auth.logout');

    // FR-12: profile → Security. The PAGE is a shell — the sessions table and
    // its actions go through /api/staff/security/* (routes/api.php), which
    // carry the full staff middleware chain. Self-service: see
    // ci/permission-exempt-routes.php for why no permission key applies.
    Route::get('security', [SecurityPageController::class, 'show'])
        ->name('auth.security.page');
});
