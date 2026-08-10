<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appBooted' => true,
    ]);
});

// The explicit auth surface (Doc 02). See that file's header for the rule it
// enforces; Fortify never auto-registers routes in this application.
require __DIR__.'/auth.php';

// ── Doc 03 §8/§9 — the four B6 RBAC screens (S19 step 9). Pages are gated
// by the same catalog keys as their APIs; mutations go through
// /api/staff/admin/* which carry the full staff middleware chain.
Route::middleware([
    'auth:staff',
    // The same resolution chain as the staff API surface: these pages QUERY
    // tenant-scoped models, so the tenant binds before the controller, the
    // tenant state gates, and the user's locale drives the server-localized
    // copy (I-5/C-08).
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('admin')->group(function (): void {
    Route::get('roles', [App\Http\Controllers\Admin\RbacScreensController::class, 'roles'])
        ->middleware('permission:admin.roles.view')
        ->name('rbac.screens.roles');
    Route::get('roles/{roleId}/edit', [App\Http\Controllers\Admin\RbacScreensController::class, 'editor'])
        ->middleware('permission:admin.roles.view')
        ->name('rbac.screens.editor');
    Route::get('users/{userId}/overrides', [App\Http\Controllers\Admin\RbacScreensController::class, 'overrides'])
        ->middleware('permission:admin.users.view')
        ->name('rbac.screens.overrides');
    Route::get('pending-grants', [App\Http\Controllers\Admin\RbacScreensController::class, 'pending'])
        ->middleware('permission:admin.roles.view')
        ->name('rbac.screens.pending');
});

// Doc 05 §8/§9 — the Telegram admin surfaces (B6-S01/S02/S03). Same staff
// chain as the RBAC screens; permission-gated with the seeded telegram.* keys.
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('admin/telegram')->group(function (): void {
    Route::get('bots', [App\Http\Controllers\Telegram\TelegramScreensController::class, 'bots'])
        ->middleware('permission:telegram.bots.view')->name('telegram.screens.bots');
    Route::get('connections', [App\Http\Controllers\Telegram\TelegramScreensController::class, 'connections'])
        ->middleware('permission:telegram.connections.manage')->name('telegram.screens.connections');
    Route::get('reply-window', [App\Http\Controllers\Telegram\TelegramScreensController::class, 'window'])
        ->middleware('permission:telegram.bots.manage')->name('telegram.screens.window');

    // Step 11 — B6-S04/S05/S06 (menu designer, templates & commands, health).
    Route::get('menu', [App\Http\Controllers\Telegram\TelegramScreensController::class, 'menu'])
        ->middleware('permission:telegram.bots.manage')->name('telegram.screens.menu');
    Route::get('templates', [App\Http\Controllers\Telegram\TelegramScreensController::class, 'templates'])
        ->middleware('permission:telegram.bots.manage')->name('telegram.screens.templates');
    Route::get('health/{botId?}', [App\Http\Controllers\Telegram\TelegramScreensController::class, 'health'])
        ->middleware('permission:telegram.health.view')->name('telegram.screens.health');

    // FR-30 test tools + the SEC-03-masked viewer (plan mapping: telegram.health.view).
    Route::get('bots/{botId}/raw-updates', [App\Http\Controllers\Telegram\HealthAdminController::class, 'rawUpdates'])
        ->middleware('permission:telegram.health.view')->name('telegram.health.raw-updates');
    // FILED INTERPRETATION (step 11): the plan's one-line mapping put "test
    // tools" under telegram.health.view, but the ReadOnlySweep doctrine
    // (Read Only accepts ZERO writes; view-tier keys are Read-Only-held)
    // caught simulate-expiry ACCEPTING a Read Only write. The WRITE tools
    // therefore ride telegram.bots.manage — the established precedent of
    // their sibling write tool, re-register (api.php). Viewing (panel,
    // raw viewer) stays on telegram.health.view as planned.
    Route::post('bots/{botId}/send-test', [App\Http\Controllers\Telegram\HealthAdminController::class, 'sendTest'])
        ->middleware(['permission:telegram.bots.manage', 'audit:telegram.test_message_sent'])
        ->name('telegram.health.send-test');
    Route::post('tools/simulate-window-expiry', [App\Http\Controllers\Telegram\HealthAdminController::class, 'simulateExpiry'])
        ->middleware(['permission:telegram.bots.manage', 'audit:telegram.window_expiry_simulated'])
        ->name('telegram.health.simulate-expiry');
});

// ── Doc 04 FS-05 — the dead-letter ops view (ruling R-17's class-2 fill).
// Same staff chain as the admin screens; parked payloads are conversation
// content, so the page is gated by the seeded conversations.view key (A-6).
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('conversations')->group(function (): void {
    Route::get('dead-letters', [App\Http\Controllers\Conversations\DeadLettersController::class, 'index'])
        ->middleware('permission:conversations.view')
        ->name('conversations.dead_letters.index');
});

/*
|------------------------------------------------------------------------------
| Inbox — saved views + prefs (Doc 07 FR-06/FR-08, S07 step 4)
|------------------------------------------------------------------------------
|
| Route → permission SAME-COMMIT from the closed catalog: CRUD + duplicate on
| tickets.view (FR-06's own words); share/unshare on views.share (the
| addition of record) with the audit: middleware (SEC-03 — actor + target).
*/
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('inbox')->group(function (): void {
    Route::get('views', [App\Http\Controllers\Inbox\SavedViewController::class, 'index'])
        ->middleware('permission:tickets.view')->name('inbox.views.index');
    // WRITES ride views.manage (R-26/A-34 — FR-06's tickets.view names the
    // AUDIENCE; Read Only's computed template can never hold this key).
    Route::post('views', [App\Http\Controllers\Inbox\SavedViewController::class, 'store'])
        ->middleware('permission:views.manage')->name('inbox.views.store');
    Route::patch('views/{viewId}', [App\Http\Controllers\Inbox\SavedViewController::class, 'update'])
        ->middleware('permission:views.manage')->name('inbox.views.update');
    Route::delete('views/{viewId}', [App\Http\Controllers\Inbox\SavedViewController::class, 'destroy'])
        ->middleware('permission:views.manage')->name('inbox.views.destroy');
    Route::post('views/{viewId}/duplicate', [App\Http\Controllers\Inbox\SavedViewController::class, 'duplicate'])
        ->middleware('permission:views.manage')->name('inbox.views.duplicate');

    Route::post('views/{viewId}/share', [App\Http\Controllers\Inbox\SavedViewController::class, 'share'])
        ->middleware(['permission:views.share', 'audit:inbox.view_shared'])->name('inbox.views.share');
    Route::delete('views/{viewId}/share', [App\Http\Controllers\Inbox\SavedViewController::class, 'unshare'])
        ->middleware(['permission:views.share', 'audit:inbox.view_unshared'])->name('inbox.views.unshare');

    Route::get('prefs', [App\Http\Controllers\Inbox\PrefsController::class, 'show'])
        ->middleware('permission:tickets.view')->name('inbox.prefs.show');
    // The R-26 principle applied to the sibling workspace-config WRITE
    // (flagged in the 4+5 report for countersign): Read Only persists no
    // server-side prefs; reading them stays tickets.view.
    Route::patch('prefs', [App\Http\Controllers\Inbox\PrefsController::class, 'update'])
        ->middleware('permission:views.manage')->name('inbox.prefs.update');
});
