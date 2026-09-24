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

            // Doc 05 FR-01/FR-03/FR-04: bot registry lifecycle (B6-S01's
            // server side; the wizard screens land at S23 step 10).
            Route::post('admin/telegram/bots', [App\Http\Controllers\Telegram\BotAdminController::class, 'store'])
                ->middleware(['permission:telegram.bots.manage', 'audit:telegram.bot_registered'])
                ->name('telegram.bots.store');
            Route::post('admin/telegram/bots/{botId}/re-register', [App\Http\Controllers\Telegram\BotAdminController::class, 'reRegister'])
                ->middleware(['permission:telegram.bots.manage', 'audit:telegram.bot_webhook_reregistered'])
                ->name('telegram.bots.re_register');
            Route::delete('admin/telegram/bots/{botId}', [App\Http\Controllers\Telegram\BotAdminController::class, 'destroy'])
                ->middleware(['permission:telegram.bots.manage', 'audit:telegram.bot_archived'])
                ->name('telegram.bots.archive');

            // Doc 05 FR-07/SEC-04/EC-01: connection confirm/reject
            // (telegram.connections.manage; B6-S02's server side).
            Route::post('admin/telegram/connections/{connectionId}/confirm', [App\Http\Controllers\Telegram\ConnectionAdminController::class, 'confirm'])
                ->middleware(['permission:telegram.connections.manage', 'audit:telegram.connection_confirmed'])
                ->name('telegram.connections.confirm');
            Route::post('admin/telegram/connections/{connectionId}/reject', [App\Http\Controllers\Telegram\ConnectionAdminController::class, 'reject'])
                ->middleware(['permission:telegram.connections.manage', 'audit:telegram.connection_rejected'])
                ->name('telegram.connections.reject');

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

            // ── Doc 03 §8/§9 — the RBAC admin API (S19 step 8). Keys from the
            // catalog; every mutation audits + events in its service.
            Route::get('admin/roles', [App\Http\Controllers\Admin\RbacRolesController::class, 'index'])
                ->middleware('permission:admin.roles.view')
                ->name('rbac.roles.index');
            Route::post('admin/roles', [App\Http\Controllers\Admin\RbacRolesController::class, 'store'])
                ->middleware(['permission:admin.roles.manage', 'audit:rbac.role.created'])
                ->name('rbac.roles.store');
            Route::put('admin/roles/{roleId}', [App\Http\Controllers\Admin\RbacRolesController::class, 'update'])
                ->middleware(['permission:admin.roles.manage', 'audit:rbac.role.updated'])
                ->name('rbac.roles.update');
            Route::delete('admin/roles/{roleId}', [App\Http\Controllers\Admin\RbacRolesController::class, 'destroy'])
                ->middleware(['permission:admin.roles.manage', 'audit:rbac.role.deleted'])
                ->name('rbac.roles.destroy');
            Route::put('admin/users/{userId}/role', [App\Http\Controllers\Admin\RbacRolesController::class, 'assign'])
                ->middleware(['permission:admin.users.manage', 'audit:rbac.user.role_changed'])
                ->name('rbac.users.assign_role');

            Route::get('admin/users/{userId}/overrides', [App\Http\Controllers\Admin\RbacOverridesController::class, 'index'])
                ->middleware('permission:admin.overrides.manage')
                ->name('rbac.overrides.index');
            Route::get('admin/users/{userId}/effective', [App\Http\Controllers\Admin\RbacOverridesController::class, 'effective'])
                ->middleware('permission:admin.users.view')
                ->name('rbac.overrides.effective');
            Route::post('admin/users/{userId}/overrides', [App\Http\Controllers\Admin\RbacOverridesController::class, 'store'])
                ->middleware(['permission:admin.overrides.manage', 'audit:rbac.override.added'])
                ->name('rbac.overrides.store');
            Route::delete('admin/users/{userId}/overrides/{overrideId}', [App\Http\Controllers\Admin\RbacOverridesController::class, 'destroy'])
                ->middleware(['permission:admin.overrides.manage', 'audit:rbac.override.removed'])
                ->name('rbac.overrides.destroy');

            Route::get('admin/pending-grants', [App\Http\Controllers\Admin\RbacPendingGrantsController::class, 'index'])
                ->middleware('permission:admin.roles.view')
                ->name('rbac.pending.index');
            Route::post('admin/pending-grants/{grantId}/approve', [App\Http\Controllers\Admin\RbacPendingGrantsController::class, 'approve'])
                ->middleware(['permission:admin.roles.view', 'audit:rbac.highrisk.confirmed'])
                ->name('rbac.pending.approve');
            Route::post('admin/pending-grants/{grantId}/reject', [App\Http\Controllers\Admin\RbacPendingGrantsController::class, 'reject'])
                ->middleware(['permission:admin.roles.view', 'audit:rbac.highrisk.rejected'])
                ->name('rbac.pending.reject');

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

            // ── Doc 04 FS-05: the dead-letter re-drive (interpretation A-6 —
            // FR-13's manual-retry precedent, tickets.reply — no key invented).
            Route::post('conversations/dead-letters/{deadLetterId}/redrive', [App\Http\Controllers\Conversations\DeadLettersController::class, 'redrive'])
                ->middleware(['permission:tickets.reply', 'audit:conversation.message_redriven'])
                ->name('conversations.dead_letters.redrive');

            // ── Doc 08 FR-09/FR-10 (S08 step 5): the identity doors — T-02
            //    names the link route. C-10 404-shape + curated payloads in
            //    the controller; AC-04's single-source law binds preview and
            //    execute to UnlinkMoveSet.
            Route::post('customers/{customerId}/identities', [App\Http\Controllers\Customers\IdentityController::class, 'link'])
                ->middleware(['permission:customers.identity.link', 'audit:customer.identity.linked'])
                ->name('customers.identities.link');
            Route::get('customers/{customerId}/identities/{identityId}/unlink-preview', [App\Http\Controllers\Customers\IdentityController::class, 'unlinkPreview'])
                ->middleware('permission:customers.identity.unlink')
                ->name('customers.identities.unlink_preview');
            Route::post('customers/{customerId}/identities/{identityId}/unlink', [App\Http\Controllers\Customers\IdentityController::class, 'unlink'])
                ->middleware(['permission:customers.identity.unlink', 'audit:customer.identity.unlinked'])
                ->name('customers.identities.unlink');

            // ── Doc 08 FR-20/T-04 (S08 step 8): the customer search door —
            //    query string in, curated hits out; scope injected server-side
            //    (SEC-02's one-path law); FS-01's degrade labeled in-band.
            //    Read door: no audit middleware (not state-mutating).
            Route::get('customers/search', [App\Http\Controllers\Customers\CustomerSearchController::class, 'search'])
                ->middleware('permission:customers.view')
                ->name('customers.search');

            // ── Doc 09 SEC-06/FR-20 (S09 step 4): the decision-log read door
            //    — ai.decisions.view, curated allowlist (the payload pair is
            //    STRUCTURALLY absent; raw payloads have NO tenant door at
            //    all — the platform-debug tier is the console's). C-05
            //    cursor; read door, no audit middleware.
            Route::get('ai/decisions', [App\Http\Controllers\Ai\AiDecisionsController::class, 'index'])
                ->middleware('permission:ai.decisions.view')
                ->name('ai.decisions.index');

            // ── Doc 08 T-07/FR-17/FR-19/SEC-04 (S08 step 9): the panel
            //    aggregate + independent sections, the profile autosave
            //    (A-37 noop declared at the service's own no-change branch),
            //    notes, the gallery serve half, export and erasure
            //    initiation. C-10 + curated payloads on every read door.
            Route::get('customers/{customerId}/panel', [App\Http\Controllers\Customers\PanelController::class, 'panel'])
                ->middleware('permission:customers.view')->name('customers.panel');
            Route::get('customers/{customerId}/panel/tickets', [App\Http\Controllers\Customers\PanelController::class, 'recentTickets'])
                ->middleware('permission:customers.view')->name('customers.panel.tickets');
            Route::get('customers/{customerId}/panel/notes', [App\Http\Controllers\Customers\PanelController::class, 'notes'])
                ->middleware('permission:customers.notes.view')->name('customers.panel.notes');
            Route::get('customers/{customerId}/panel/files', [App\Http\Controllers\Customers\PanelController::class, 'files'])
                ->middleware('permission:customers.view')->name('customers.panel.files');
            // index rides the QUERY STRING: every path param stays uuid-shaped
            // so Doc 03's RouteCoverageSweep exercises the 403 (a whereNumber
            // param 404s at the router BEFORE the permission middleware —
            // found by that sweep on CI, the held battery's cost again).
            Route::get('customers/{customerId}/panel/files/{messageId}/url', [App\Http\Controllers\Customers\PanelController::class, 'fileUrl'])
                ->middleware('permission:customers.view')->name('customers.panel.file_url');
            Route::patch('customers/{customerId}/profile', [App\Http\Controllers\Customers\ProfileController::class, 'update'])
                ->middleware(['permission:customers.edit', 'audit:customer.updated'])->name('customers.profile.update');
            Route::post('customers/{customerId}/profile/apply-detected', [App\Http\Controllers\Customers\ProfileController::class, 'applyDetected'])
                ->middleware(['permission:customers.edit', 'audit:customer.updated'])->name('customers.profile.apply_detected');
            Route::post('customers/{customerId}/notes', [App\Http\Controllers\Customers\NotesController::class, 'add'])
                ->middleware(['permission:customers.notes.add', 'audit:customer.note.added'])->name('customers.notes.add');
            Route::post('customers/{customerId}/export', [App\Http\Controllers\Customers\ExportController::class, 'request'])
                ->middleware(['permission:customers.export', 'audit:customer.export_requested'])->name('customers.export.request');
            Route::get('customers/{customerId}/export/{exportId}', [App\Http\Controllers\Customers\ExportController::class, 'url'])
                ->middleware('permission:customers.export')->name('customers.export.url');
            Route::post('customers/{customerId}/erasure', [App\Http\Controllers\Customers\ExportController::class, 'initiateErasure'])
                ->middleware(['permission:customers.delete', 'audit:customer.erasure.initiated'])->name('customers.erasure.initiate');

            // ── Doc 08 FR-16/SEC-05/EC-09 (S08 step 9b, amendment addition 9
            //    countersigned): the betting-fields card + value writer.
            Route::get('customers/{customerId}/panel/betting-fields', [App\Http\Controllers\Customers\FieldsController::class, 'card'])
                ->middleware('permission:customers.view')->name('customers.panel.betting_fields');
            // ── escalation #3 part 2: the tab-badge door — the SAME
            //    CustomerTabBadge brain the workspace render uses (E03: the
            //    unpermitted answer is false, computed server-side).
            Route::get('customers/tab-badge', [App\Http\Controllers\Customers\TabBadgeController::class, 'show'])
                ->middleware('permission:customers.view')->name('customers.tab_badge');
            Route::patch('customers/{customerId}/fields', [App\Http\Controllers\Customers\FieldsController::class, 'update'])
                ->middleware(['permission:customers.edit', 'audit:customer.fields_changed'])->name('customers.fields.update');

            // ── Doc 08 FR-11/12/13, FR-14/15, FR-18 (S08 step 11): the merge
            //    flow's FIRST HUMAN REACH (every guard falsified first —
            //    §21's ordering), the RG doors on the no-cache path, and the
            //    block/unblock pair (A-39). Services audit; doors declare.
            Route::post('customers/{customerId}/merge-preview', [App\Http\Controllers\Customers\MergeController::class, 'preview'])
                ->middleware('permission:customers.merge')->name('customers.merge.preview');
            Route::post('customers/{customerId}/merge', [App\Http\Controllers\Customers\MergeController::class, 'merge'])
                ->middleware(['permission:customers.merge', 'audit:customer.merged'])->name('customers.merge.execute');
            Route::get('customers/{customerId}/unmerge/{snapshotId}', [App\Http\Controllers\Customers\MergeController::class, 'unmergePreview'])
                ->middleware('permission:customers.merge')->name('customers.unmerge.preview');
            Route::post('customers/{customerId}/unmerge/{snapshotId}', [App\Http\Controllers\Customers\MergeController::class, 'unmerge'])
                ->middleware(['permission:customers.merge', 'audit:customer.unmerged'])->name('customers.unmerge.execute');
            Route::get('customers/{customerId}/rg-flag', [App\Http\Controllers\Customers\RgController::class, 'show'])
                ->middleware('permission:customers.rg_flag.view')->name('customers.rg.show');
            Route::post('customers/{customerId}/rg-flag', [App\Http\Controllers\Customers\RgController::class, 'set'])
                ->middleware(['permission:customers.rg_flag.set', 'audit:customer.rg_flag.set'])->name('customers.rg.set');
            Route::post('customers/{customerId}/rg-flag/clear', [App\Http\Controllers\Customers\RgController::class, 'clear'])
                ->middleware(['permission:customers.rg_flag.set', 'audit:customer.rg_flag.cleared'])->name('customers.rg.clear');
            Route::post('customers/{customerId}/block', [App\Http\Controllers\Customers\BlockController::class, 'block'])
                ->middleware(['permission:customers.block', 'audit:customer.blocked'])->name('customers.block');
            Route::post('customers/{customerId}/unblock', [App\Http\Controllers\Customers\BlockController::class, 'unblock'])
                ->middleware(['permission:customers.block', 'audit:customer.unblocked'])->name('customers.unblock');

            // ── Doc 08 FR-20 (S08 step 12): the directory table door — the
            //    ONE scope brain; G03's rg-filter absence is query-level.
            Route::get('customers/directory', [App\Http\Controllers\Customers\DirectoryController::class, 'list'])
                ->middleware('permission:customers.view')->name('customers.directory');
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
    /*
     | Doc 05 S23 step 4: the REAL handler replaces the probe on the SAME
     | middleware contract. The bot key rides the URL path (Telegram sends
     | only the registered URL + secret_token header); the path shim copies
     | it into the header the binder already reads, so the S15 order —
     | binder -> throttle -> secret -> state — is EXECUTED, not re-built
     | (T-02: bot resolution BEFORE secret verification; the 404-vs-403 pair
     | in WebhookRouteTest is the order proof). {bot_key?} is optional so the
     | Doc 02 header-borne proof suites keep driving the same stack.
     */
    Route::middleware([
        'telegram.webhook-key',
        'tenant:telegram_bot_key',
        'webhook.throttle',
        'webhook.auth:secret_header,X-Telegram-Bot-Api-Secret-Token',
        'tenant.state:inbound',
    ])
        ->post('webhooks/telegram/{bot_key?}', [App\Http\Controllers\Telegram\TelegramWebhookController::class, 'ingest'])
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

        // Doc 12 FR-25/SEC-05 (S12 step 12): the CSAT submission door. The
        // SURVEY TOKEN is the credential (hash compare in the domain door), so
        // no visitor guard sits here — same trust shape as /session issuance;
        // throttled at the route on top of the in-door per-token counter.
        Route::middleware(['tenant:widget_key', App\Http\Middleware\WidgetCors::class, 'tenant.state:inbound', 'throttle:30,1'])
            ->post('csat', [App\Http\Controllers\Widget\CsatController::class, 'store'])
            ->name('csat.widget.submit');

        // Doc 13 T-08 (S13 step 3): the loader's beacons arrive BEFORE any session
        // exists (a blocked origin has no visitor), so this door sits OUTSIDE the
        // visitor guard — widget key + CORS + tenant state only, the /session
        // shape — and answers 204 to everything (NFR-06: a beacon never errors a
        // visitor). The widget key's own quota bounds it.
        Route::middleware(['tenant:widget_key', App\Http\Middleware\WidgetCors::class, 'tenant.state:inbound', 'throttle:120,1'])
            ->post('beacon', [App\Http\Controllers\Widget\WidgetBeaconController::class, 'store'])
            ->name('widget.beacon');

        Route::middleware(['tenant:widget_key', App\Http\Middleware\WidgetCors::class, App\Http\Middleware\AuthenticateWidgetVisitor::class, 'tenant.state:inbound'])
            ->group(function (): void {
                Route::get('context', [IngressProbeController::class, 'show'])
                    ->name('auth.widget.context');

                /*
                 | ── Doc 10 FR-18 (S10 step 11): the widget Help tab's API — the first real
                 | endpoints mounted on this stack (the mounting this group's docblock
                 | anticipates). PUBLIC + PUBLISHED content only; the visitor principal is
                 | the feedback hash; rendering is Doc 13's ([D13]).
                 */
                Route::get('kb/categories', [App\Http\Controllers\Widget\KbHelpController::class, 'categories'])
                    ->name('kb.widget.categories');
                Route::get('kb/search', [App\Http\Controllers\Widget\KbHelpController::class, 'search'])
                    ->name('kb.widget.search');
                Route::get('kb/articles/{articleId}', [App\Http\Controllers\Widget\KbHelpController::class, 'article'])
                    ->name('kb.widget.article');
                Route::post('kb/feedback', [App\Http\Controllers\Widget\KbHelpController::class, 'feedback'])
                    ->name('kb.widget.feedback');
                Route::get('kb/media/{articleId}/{file}', [App\Http\Controllers\Widget\KbHelpController::class, 'media'])
                    ->name('kb.widget.media');

                // FR-21's "that visitor's own Reverb channels": the widget
                // surface's OWN broadcasting-auth door. The visitor channel in
                // routes/channels.php names the widget guard; the staff door
                // (Doc 07's, when it builds one) will never accept a visitor
                // token any more than this one accepts a staff session.
                Route::post('broadcasting/auth', [Illuminate\Broadcasting\BroadcastController::class, 'authenticate'])
                    ->name('auth.widget.broadcast');

                /*
                 | ── Doc 13 T-03 (S13 step 3): the visitor's conversation doors — the REAL
                 | endpoints this stack was probe-only without (the Doc 02 step-10
                 | docblock's promise). Every door is scoped to the VISITOR principal's
                 | OWN conversation by the channel key (AC-04, A-61); the per-visitor
                 | throttle (ThrottleVisitorToken, the FR-25 shape) is declared AFTER
                 | the guard, `:messages` on the message door. Permission-exempt +
                 | audit-exempt with reasons (ci/*-exempt-routes.php): visitors hold no
                 | staff permission; the message row / the delivery transition / the
                 | context row ARE the durable record.
                 */
                Route::middleware([App\Http\Middleware\ThrottleVisitorToken::class])->group(function (): void {
                    Route::get('config', [App\Http\Controllers\Widget\WidgetConfigController::class, 'show'])
                        ->name('widget.config');
                    Route::get('messages', [App\Http\Controllers\Widget\WidgetMessagesController::class, 'index'])
                        ->name('widget.messages.index');
                    // Doc 13 SEC-06 (S13 step 12): the visitor's OWN transcript, text download; notes structurally absent; rate-limited per visitor.
                    Route::get('transcript', [App\Http\Controllers\Widget\WidgetTranscriptController::class, 'show'])->name('widget.transcript');
                    Route::post('messages', [App\Http\Controllers\Widget\WidgetMessagesController::class, 'store'])
                        ->middleware(App\Http\Middleware\ThrottleVisitorToken::class.':messages')
                        ->name('widget.messages.store');
                    Route::post('media', [App\Http\Controllers\Widget\WidgetMediaController::class, 'store'])
                        ->middleware(App\Http\Middleware\ThrottleVisitorToken::class.':messages') // FR-16: an upload spends the message budget
                        ->name('widget.media.store');
                    Route::get('media/{messageId}/{index}', [App\Http\Controllers\Widget\WidgetMediaController::class, 'show'])
                        ->where('index', '[0-9]{1,2}')->name('widget.media.show');
                    Route::post('typing', [App\Http\Controllers\Widget\WidgetMessagesController::class, 'typing'])
                        ->name('widget.typing');
                    Route::post('receipts', [App\Http\Controllers\Widget\WidgetMessagesController::class, 'receipts'])
                        ->name('widget.receipts');
                    Route::post('context', [App\Http\Controllers\Widget\WidgetContextController::class, 'store'])
                        ->name('widget.context');
                    Route::post('identify', [App\Http\Controllers\Widget\WidgetIdentifyController::class, 'store'])
                        ->name('widget.identify');
                    Route::get('forms/{versionId}', [App\Http\Controllers\Widget\WidgetFormStepsController::class, 'show'])
                        ->name('widget.forms.show');
                    Route::post('forms/{versionId}/steps', [App\Http\Controllers\Widget\WidgetFormStepsController::class, 'store'])
                        ->middleware(App\Http\Middleware\ThrottleVisitorToken::class.':messages') // STOP-5B: a form submit spends the visitor's message budget
                        ->name('widget.forms.steps');
                });
            });
    });

    // Doc 14 FR-13 / SEC-01 (P-DLR-AUTH, S14 step 7): the Doc 01 DLR probe is
    // REPLACED by the real handler on TWO auth surfaces, both behind the same
    // Doc 02 stack (binder → throttle → secret → state). The {gateway_key}
    // segment resolves the tenant (the sms.dlr-key shim); the ACK is 202 and
    // every mutation happens in the queued DLR pipeline (sms-dlr lane).
    //   url_secret — an UNSIGNED callback (GeezSMS): the per-gateway secret
    //                rides {dlr_secret}; replay = 202 ignored; no secret → 403.
    //   body_hmac  — a SIGNING gateway (the REFERENCE fixture, the sidecar):
    //                FR-24's headers; the path key optional so Doc 02's
    //                header-borne proof suites run unmodified.
    Route::middleware([
        'sms.dlr-key',
        'tenant:sms_gateway_key',
        'webhook.throttle',
        'webhook.auth:url_secret',
        'tenant.state:inbound',
    ])
        ->post('webhooks/sms/dlr/{gateway_key}/{dlr_secret}', [App\Http\Controllers\Sms\SmsDlrController::class, 'ingest'])
        ->name('foundation.webhook.sms_dlr');

    Route::middleware([
        'sms.dlr-key',
        'tenant:sms_gateway_key',
        'webhook.throttle',
        'webhook.auth:body_hmac',
        'tenant.state:inbound',
    ])
        ->post('webhooks/sms/dlr/{gateway_key?}', [App\Http\Controllers\Sms\SmsDlrController::class, 'ingest'])
        ->name('foundation.webhook.sms_dlr_signed');

    // Doc 14 FR-15 (S14 step 8): the MO door — SMS is notification-only (N-15),
    // so this PARSES for STOP and mints NOTHING else; same auth stack as the DLR
    // door; capability-gated inbound_stop; non-STOP = counted drop.
    Route::middleware([
        'sms.dlr-key',
        'tenant:sms_gateway_key',
        'webhook.throttle',
        'webhook.auth:url_secret',
        'tenant.state:inbound',
        'audit:sms.optout.added',
    ])
        ->post('webhooks/sms/mo/{gateway_key}/{dlr_secret}', [App\Http\Controllers\Sms\SmsMoController::class, 'ingest'])
        ->name('foundation.webhook.sms_mo');
});
