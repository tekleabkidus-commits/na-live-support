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
    // B1-S01: the workspace shell page (S07 step 8) — a pure read.
    Route::get('/', [App\Http\Controllers\Inbox\WorkspaceController::class, 'show'])
        ->middleware('permission:tickets.view')->name('inbox.workspace');
    // FR-12: the cursor list page — a pure read on the audience key.
    Route::get('list', [App\Http\Controllers\Inbox\ListController::class, 'index'])
        ->middleware('permission:tickets.view')->name('inbox.list');
    // FR-14/15: the thread page + the Doc 04 EC-08 anchor window; EC-01's
    // 404 pattern for missing AND out-of-scope alike (sabotage #15's target).
    Route::get('thread/{conversationId}', [App\Http\Controllers\Inbox\ThreadController::class, 'show'])
        ->middleware('permission:conversations.view')->name('inbox.thread');
    // B12-S02: preserved history — HistoryReader owns verdict + audit.
    Route::get('messages/{messageId}/history', [App\Http\Controllers\Inbox\ThreadController::class, 'messageHistory'])
        ->middleware('permission:conversations.view')->name('inbox.message.history');
    // §9 print (SEC-05: note gate + watermark; sabotage #20's target).
    Route::get('thread/{conversationId}/print', [App\Http\Controllers\Inbox\ThreadController::class, 'print'])
        ->middleware('permission:conversations.view')->name('inbox.thread.print');
    // ── The composer's doors (S07 step 11). MODE SAFETY IS STRUCTURAL:
    // reply and note are SEPARATE routes to SEPARATE services — the note
    // door has no adapter path beyond it (N-04; sabotage #14's target).
    Route::post('thread/{conversationId}/reply', [App\Http\Controllers\Inbox\ComposerController::class, 'reply'])
        ->middleware('permission:tickets.reply')->name('inbox.composer.reply');
    Route::post('thread/{conversationId}/note', [App\Http\Controllers\Inbox\ComposerController::class, 'note'])
        ->middleware(['permission:tickets.note', 'audit:conversation.note_added'])->name('inbox.composer.note');
    Route::patch('thread/{conversationId}/draft', [App\Http\Controllers\Inbox\ComposerController::class, 'draft'])
        ->middleware('permission:tickets.reply')->name('inbox.composer.draft');
    Route::post('thread/{conversationId}/transition', [App\Http\Controllers\Inbox\ComposerController::class, 'transition'])
        ->middleware(['permission:tickets.status.change', 'audit:ticket.status_changed'])->name('inbox.composer.transition');
    // FR-15 deep links: /inbox/t/{ticket} and /inbox/c/{conversation} — the
    // workspace opens with the thread resolved; EC-01 out-of-scope → the
    // 404-pattern PAGE with "search instead", never an existence oracle.
    Route::get('t/{ticketId}', [App\Http\Controllers\Inbox\WorkspaceController::class, 'openTicket'])
        ->middleware('permission:tickets.view')->name('inbox.deeplink.ticket');
    Route::get('c/{conversationId}', [App\Http\Controllers\Inbox\WorkspaceController::class, 'openConversation'])
        ->middleware('permission:tickets.view')->name('inbox.deeplink.conversation');
    // Read markers (SCHEMA AMENDMENT 1): a per-user workspace-STATE write —
    // rides views.manage per the R-26 AMENDMENT's generalized statement (the
    // FOURTH sibling, cited not re-ruled; flagged at the step-9 checkpoint).
    // Read Only's unread state stays session-local, same as the prefs rider.
    Route::post('read', [App\Http\Controllers\Inbox\ListController::class, 'markRead'])
        ->middleware('permission:views.manage')->name('inbox.read');
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

    // T-05: server-side selections — MINT rides views.manage (the R-26
    // principle, third application, flagged for countersign at the 6+7
    // report: a token row is an ephemeral workspace write; the sweep's
    // zero-writes bar for Read Only is absolute, and RO could execute no
    // bulk action regardless). Execute is audited; per-item permissions are
    // Doc 06's live checks (SEC-04 structural).
    Route::post('selections', [App\Http\Controllers\Inbox\SelectionController::class, 'store'])
        ->middleware('permission:views.manage')->name('inbox.selections.store');
    Route::post('selections/{tokenId}/execute', [App\Http\Controllers\Inbox\SelectionController::class, 'execute'])
        ->middleware(['permission:views.manage', 'audit:inbox.selection_executed'])->name('inbox.selections.execute');

    // FR-23/SEC-02: the search proxy — the ONLY search path; the request
    // contract is the query string alone.
    Route::get('search', App\Http\Controllers\Inbox\SearchController::class)
        ->middleware('permission:tickets.view')->name('inbox.search');

    // T-07: the single reconciliation path (GET — read-only replay; EC-08's
    // 429-on-connect rides the inbox-sync limiter).
    Route::get('sync', App\Http\Controllers\Inbox\SyncController::class)
        ->middleware(['permission:tickets.view', 'throttle:inbox-sync'])->name('inbox.sync');
    // The R-26 principle applied to the sibling workspace-config WRITE
    // (flagged in the 4+5 report for countersign): Read Only persists no
    // server-side prefs; reading them stays tickets.view.
    Route::patch('prefs', [App\Http\Controllers\Inbox\PrefsController::class, 'update'])
        ->middleware('permission:views.manage')->name('inbox.prefs.update');
});
