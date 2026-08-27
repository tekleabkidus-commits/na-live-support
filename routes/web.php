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

    // R-22's class-2 fill (S07 step 14) — the tag rename/merge surface over
    // Doc 06's T-07 engine. Same catalog key as every tag write (the catalog
    // is closed); the rewrite door declares the engine's own audit action.
    Route::get('tags', [App\Http\Controllers\Admin\TagScreensController::class, 'manager'])
        ->middleware('permission:tickets.tags.manage')
        ->name('tags.screens.manager');
    Route::post('tags/rewrite', [App\Http\Controllers\Admin\TagScreensController::class, 'rewrite'])
        ->middleware(['permission:tickets.tags.manage', 'audit:ticket.tags_rewritten'])
        ->name('tags.rewrite');
});

// Doc 08 §8/§9 (S08 step 11) — the customer profile page and BR-05's
// forever-resolving tombstone. Same staff chain as the admin pages.
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->group(function (): void {
    // The directory page BEFORE the {customerId} catch (a literal segment
    // beats the param; declared first for the reader's sake).
    Route::get('customers', [App\Http\Controllers\Customers\DirectoryController::class, 'page'])
        ->middleware('permission:customers.view')->name('customers.directory.page');
    Route::get('customers/{customerId}', [App\Http\Controllers\Customers\ProfilePageController::class, 'show'])
        ->middleware('permission:customers.view')
        ->name('customers.profile.page');
});

// Doc 09 FR-18 — the AI Backoffice (S09 step 12, Option A [B8]). Same staff
// chain; permission-gated with the CLOSED ai.* keys. Mutations ride the
// domain doors that audit themselves (ConfigVersions publish/rollback = the
// ai.config.* rows; KillSwitches::flip = ai.killswitch.changed) — no audit:
// middleware, the Doc 06 property-door precedent (exemptions name it).
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('admin/ai')->group(function (): void {
    Route::get('persona', [App\Http\Controllers\Ai\AiBackofficeController::class, 'persona'])
        ->middleware('permission:ai.chatbot.configure')->name('ai.backoffice.persona');
    Route::post('persona/draft', [App\Http\Controllers\Ai\AiBackofficeController::class, 'personaDraft'])
        ->middleware('permission:ai.chatbot.configure')->name('ai.backoffice.persona.draft');
    Route::post('persona/{versionId}/preview', [App\Http\Controllers\Ai\AiBackofficeController::class, 'personaPreview'])
        ->middleware('permission:ai.chatbot.configure')->name('ai.backoffice.persona.preview');
    Route::get('persona/preview/{previewId}', [App\Http\Controllers\Ai\AiBackofficeController::class, 'personaPreviewShow'])
        ->middleware('permission:ai.chatbot.configure')->name('ai.backoffice.persona.preview_show');
    Route::get('guardrails', [App\Http\Controllers\Ai\AiBackofficeController::class, 'guardrails'])
        ->middleware('permission:ai.guardrails.edit')->name('ai.backoffice.guardrails');
    Route::post('guardrails/draft', [App\Http\Controllers\Ai\AiBackofficeController::class, 'guardrailsDraft'])
        ->middleware('permission:ai.guardrails.edit')->name('ai.backoffice.guardrails.draft');
    // publish/rollback: the controller re-checks the KIND's own key (persona → ai.chatbot.configure, guardrails → ai.guardrails.edit);
    // the route gate is the weaker of the two so both kinds reach the re-check, never a door without a gate.
    Route::post('versions/{versionId}/publish', [App\Http\Controllers\Ai\AiBackofficeController::class, 'publish'])
        ->middleware('permission:ai.chatbot.configure')->name('ai.backoffice.publish');
    Route::post('versions/{versionId}/rollback', [App\Http\Controllers\Ai\AiBackofficeController::class, 'rollback'])
        ->middleware('permission:ai.chatbot.configure')->name('ai.backoffice.rollback');
    Route::get('thresholds', [App\Http\Controllers\Ai\AiBackofficeController::class, 'thresholds'])
        ->middleware('permission:ai.thresholds.edit')->name('ai.backoffice.thresholds');
    Route::post('thresholds/simulate', [App\Http\Controllers\Ai\AiBackofficeController::class, 'simulate'])
        ->middleware('permission:ai.thresholds.edit')->name('ai.backoffice.thresholds.simulate');
    Route::post('thresholds/publish', [App\Http\Controllers\Ai\AiBackofficeController::class, 'thresholdsPublish'])
        ->middleware('permission:ai.thresholds.edit')->name('ai.backoffice.thresholds.publish');
    Route::get('kill-switches', [App\Http\Controllers\Ai\AiBackofficeController::class, 'killSwitches'])
        ->middleware('permission:ai.killswitch')->name('ai.backoffice.killswitches');
    Route::post('kill-switches/flip', [App\Http\Controllers\Ai\AiBackofficeController::class, 'killSwitchFlip'])
        ->middleware('permission:ai.killswitch')->name('ai.backoffice.killswitches.flip');
    Route::get('banner', [App\Http\Controllers\Ai\AiBackofficeController::class, 'banner'])
        ->middleware('permission:tickets.view')->name('ai.backoffice.banner'); // AC-08: every staff member sees the banner
    Route::get('decisions', [App\Http\Controllers\Ai\AiBackofficeController::class, 'decisions'])
        ->middleware('permission:ai.decisions.view')->name('ai.backoffice.decisions');
    // S10 step 0.4 (R-D10-1 as amended at the plan rulings): the provider SWITCH
    // DOOR — JSON only; the B8-S08 screen lands in the [B8] pass. The mutation
    // rides ConfigVersions (ai.config.published) + the ai.providers.switched
    // high-visibility row written by the domain door itself.
    Route::get('providers', [App\Http\Controllers\Ai\AiBackofficeController::class, 'providers'])
        ->middleware('permission:ai.providers.manage')->name('ai.backoffice.providers');
    Route::post('providers/routing', [App\Http\Controllers\Ai\AiBackofficeController::class, 'providersRouting'])
        ->middleware(['permission:ai.providers.manage', 'audit:ai.providers.switched'])->name('ai.backoffice.providers.routing');
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
    Route::get('thread/{conversationId}/draft', [App\Http\Controllers\Inbox\ComposerController::class, 'draftShow'])
        ->middleware('permission:tickets.reply')->name('inbox.composer.draft_show');
    Route::patch('thread/{conversationId}/draft', [App\Http\Controllers\Inbox\ComposerController::class, 'draft'])
        ->middleware('permission:tickets.reply')->name('inbox.composer.draft');
    Route::post('thread/{conversationId}/transition', [App\Http\Controllers\Inbox\ComposerController::class, 'transition'])
        ->middleware(['permission:tickets.status.change', 'audit:ticket.status_changed'])->name('inbox.composer.transition');
    // ── Doc 09 FR-14/15/16 (S09 step 10): the Copilot's doors — the Doc 07
    // Copilot-tab + composer-AI-slot fills. Generation is QUEUED (202 +
    // request id; the card streams to the agent's user channel) — no
    // provider call inline, no send path anywhere in this block (BR-01;
    // CopilotNeverAutoSendTest). insert/sent gated ai.copilot.insert_draft
    // (FR-14's own words); the rest on ai.copilot.use. FR-16 outcomes are
    // the decision log's own column — the audit-exempt reasons name it.
    Route::get('ai/copilot/{conversationId}/panel', [App\Http\Controllers\Ai\CopilotController::class, 'panel'])
        ->middleware('permission:ai.copilot.use')->name('inbox.ai.copilot.panel');
    Route::post('ai/copilot/{conversationId}/draft', [App\Http\Controllers\Ai\CopilotController::class, 'draft'])
        ->middleware('permission:ai.copilot.use')->name('inbox.ai.copilot.draft');
    Route::post('ai/copilot/{conversationId}/actions/{action}', [App\Http\Controllers\Ai\CopilotController::class, 'action'])
        ->middleware('permission:ai.copilot.use')->name('inbox.ai.copilot.action');
    Route::get('ai/copilot/{conversationId}/requests/{requestId}', [App\Http\Controllers\Ai\CopilotController::class, 'request'])
        ->middleware('permission:ai.copilot.use')->name('inbox.ai.copilot.request');
    Route::post('ai/copilot/artifacts/{artifactId}/insert', [App\Http\Controllers\Ai\CopilotController::class, 'insert'])
        ->middleware('permission:ai.copilot.insert_draft')->name('inbox.ai.copilot.insert');
    Route::post('ai/copilot/artifacts/{artifactId}/sent', [App\Http\Controllers\Ai\CopilotController::class, 'sent'])
        ->middleware('permission:ai.copilot.insert_draft')->name('inbox.ai.copilot.sent');
    Route::post('ai/copilot/artifacts/{artifactId}/discard', [App\Http\Controllers\Ai\CopilotController::class, 'discard'])
        ->middleware('permission:ai.copilot.use')->name('inbox.ai.copilot.discard');
    // ── S07 step 13: thin doors over Doc 06's headless property services for
    // FR-31's I/A/P/T bindings and B2-S01's swipe-claim + actions sheet. The
    // services check the SAME keys internally and audit internally — the
    // middleware here is the route→permission mapping gate's layer; no audit:
    // middleware because the domain writes the audit row itself (Doc 06).
    Route::post('t/{ticketId}/claim', [App\Http\Controllers\Inbox\TicketActionsController::class, 'claim'])
        ->middleware(['permission:tickets.assign_self', 'audit:ticket.assigned'])->name('inbox.ticket.claim');
    Route::post('t/{ticketId}/unassign', [App\Http\Controllers\Inbox\TicketActionsController::class, 'unassign'])
        ->middleware(['permission:tickets.assign', 'audit:ticket.assigned'])->name('inbox.ticket.unassign');
    Route::post('t/{ticketId}/assign', [App\Http\Controllers\Inbox\TicketActionsController::class, 'assign'])
        ->middleware(['permission:tickets.assign', 'audit:ticket.assigned'])->name('inbox.ticket.assign');
    Route::post('t/{ticketId}/priority', [App\Http\Controllers\Inbox\TicketActionsController::class, 'priority'])
        ->middleware(['permission:tickets.priority.change', 'audit:ticket.priority_changed'])->name('inbox.ticket.priority');
    // Tags split add/remove: the owning service writes ticket.tagged OR
    // ticket.untagged, and the audit declaration verifies ONE action per route.
    Route::post('t/{ticketId}/tags/add', [App\Http\Controllers\Inbox\TicketActionsController::class, 'tagsAdd'])
        ->middleware(['permission:tickets.tags.manage', 'audit:ticket.tagged'])->name('inbox.ticket.tags.add');
    Route::post('t/{ticketId}/tags/remove', [App\Http\Controllers\Inbox\TicketActionsController::class, 'tagsRemove'])
        ->middleware(['permission:tickets.tags.manage', 'audit:ticket.untagged'])->name('inbox.ticket.tags.remove');
    Route::post('t/{ticketId}/subject', [App\Http\Controllers\Inbox\TicketActionsController::class, 'subject'])
        ->middleware(['permission:tickets.edit', 'audit:ticket.subject_changed'])->name('inbox.ticket.subject');
    Route::get('merge/preview', [App\Http\Controllers\Inbox\MergeController::class, 'preview'])
        ->middleware('permission:tickets.merge')->name('inbox.merge.preview');
    Route::post('merge', [App\Http\Controllers\Inbox\MergeController::class, 'merge'])
        ->middleware(['permission:tickets.merge', 'audit:ticket.merged'])->name('inbox.merge');
    Route::get('t/{ticketId}/followers', [App\Http\Controllers\Inbox\TicketActionsController::class, 'followers'])
        ->middleware('permission:tickets.view')->name('inbox.ticket.followers');
    Route::post('t/{ticketId}/follow', [App\Http\Controllers\Inbox\TicketActionsController::class, 'follow'])
        ->middleware(['permission:tickets.follow', 'audit:ticket.follower_added'])->name('inbox.ticket.follow');
    Route::post('t/{ticketId}/unfollow', [App\Http\Controllers\Inbox\TicketActionsController::class, 'unfollow'])
        ->middleware(['permission:tickets.follow', 'audit:ticket.follower_removed'])->name('inbox.ticket.unfollow');
    // B1-S04's operation doors + C7/C9/C10/C11 (step 14) — thin over Doc 06's
    // owning services; each declares the action its service writes.
    Route::post('t/{ticketId}/unmerge', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'unmerge'])
        ->middleware(['permission:tickets.merge', 'audit:ticket.unmerged'])->name('inbox.ticket.unmerge');
    // C9 lapsed (ruling 2026-08-13): FR-08's new-ticket-and-link recourse —
    // the service audits ticket.created itself.
    Route::post('t/{ticketId}/follow-up', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'followUp'])
        ->middleware(['permission:tickets.create', 'audit:ticket.created'])->name('inbox.ticket.follow_up');
    Route::post('t/{ticketId}/spam', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'spam'])
        ->middleware(['permission:tickets.spam.mark', 'audit:ticket.spam_marked'])->name('inbox.ticket.spam');
    Route::post('t/{ticketId}/unspam', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'unspam'])
        ->middleware(['permission:tickets.spam.mark', 'audit:ticket.spam_unmarked'])->name('inbox.ticket.unspam');
    Route::post('t/{ticketId}/escalate', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'escalate'])
        ->middleware(['permission:tickets.escalate', 'audit:ticket.escalated'])->name('inbox.ticket.escalate');
    Route::post('t/{ticketId}/deescalate', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'deescalate'])
        ->middleware(['permission:tickets.escalate', 'audit:ticket.deescalated'])->name('inbox.ticket.deescalate');
    Route::post('t/{ticketId}/split', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'split'])
        ->middleware(['permission:tickets.split', 'audit:ticket.split'])->name('inbox.ticket.split');
    Route::post('t/{ticketId}/transfer', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'transfer'])
        ->middleware(['permission:tickets.department.change', 'audit:ticket.transferred'])->name('inbox.ticket.transfer');
    Route::post('t/{ticketId}/reopen', [App\Http\Controllers\Inbox\TicketOperationsController::class, 'reopen'])
        ->middleware(['permission:tickets.reopen', 'audit:ticket.status_changed'])->name('inbox.ticket.reopen');

    Route::get('t/{ticketId}/timeline', [App\Http\Controllers\Inbox\TicketActionsController::class, 'timeline'])
        ->middleware('permission:tickets.view')->name('inbox.ticket.timeline');
    Route::get('assignees', [App\Http\Controllers\Inbox\TicketActionsController::class, 'assignees'])
        ->middleware('permission:tickets.assign')->name('inbox.assignees');
    Route::get('tags/suggest', [App\Http\Controllers\Inbox\TicketActionsController::class, 'suggestTags'])
        ->middleware('permission:tickets.tags.manage')->name('inbox.tags.suggest');
    // ── FR-26 bell + FR-28 status (S07 step 12). Reads on the audience
    // key; mark-all-read + set-own-status are per-user workspace-state
    // writes on views.manage (R-26 AMENDMENT, FIFTH + SIXTH siblings —
    // cited, flagged at the checkpoint). Force = Doc 03's key + audit.
    Route::get('notifications', [App\Http\Controllers\Inbox\StatusNotificationsController::class, 'notifications'])
        ->middleware('permission:tickets.view')->name('inbox.notifications');
    Route::post('notifications/read-all', [App\Http\Controllers\Inbox\StatusNotificationsController::class, 'markAllRead'])
        ->middleware('permission:views.manage')->name('inbox.notifications.read_all');
    Route::get('status', [App\Http\Controllers\Inbox\StatusNotificationsController::class, 'currentStatus'])
        ->middleware('permission:tickets.view')->name('inbox.status');
    Route::post('status', [App\Http\Controllers\Inbox\StatusNotificationsController::class, 'setStatus'])
        ->middleware('permission:views.manage')->name('inbox.status.set');
    Route::post('status/heartbeat', [App\Http\Controllers\Inbox\StatusNotificationsController::class, 'heartbeat'])
        ->middleware('permission:views.manage')->name('inbox.status.heartbeat');
    Route::post('status/{userId}/force', [App\Http\Controllers\Inbox\StatusNotificationsController::class, 'forceStatus'])
        ->middleware(['permission:supervision.agent_status.force', 'audit:agent.status_forced'])->name('inbox.status.force');
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

    // Unit D (C15/C16): the id-based bulk doors — the drawn bar executes on
    // "3 selected", which the token door (whole-view by design, T-05) cannot
    // carry. Writes ride views.manage (the R-26 principle, as the token doors
    // do); the progress read rides tickets.view. Per-item verdicts stay
    // Doc 06's live checks (SEC-04 structural).
    Route::post('bulk', [App\Http\Controllers\Inbox\BulkJobsController::class, 'store'])
        ->middleware(['permission:views.manage', 'audit:inbox.bulk_submitted'])->name('inbox.bulk.store');
    Route::get('bulk-jobs', [App\Http\Controllers\Inbox\BulkJobsController::class, 'index'])
        ->middleware('permission:tickets.view')->name('inbox.bulk.index');
    Route::post('bulk-jobs/{jobId}/cancel', [App\Http\Controllers\Inbox\BulkJobsController::class, 'cancel'])
        ->middleware(['permission:views.manage', 'audit:inbox.bulk_stop_requested'])->name('inbox.bulk.cancel');
    Route::post('bulk-jobs/{jobId}/retry', [App\Http\Controllers\Inbox\BulkJobsController::class, 'retry'])
        ->middleware(['permission:views.manage', 'audit:inbox.bulk_submitted'])->name('inbox.bulk.retry');

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
