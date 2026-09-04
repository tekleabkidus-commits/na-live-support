<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appBooted' => true,
    ]);
});

// ── Doc 13 T-01 / FR-01 / FR-04 — the widget's two key-addressed documents
// (S13 step 6). The key rides the PATH; WidgetKeyFromPath hands it to the ONE
// resolver. `tenant.state:outbound`: a suspended operator must not appear to be
// operating — the shim is refused, the host page stays untouched. The `web`
// group appends SecurityHeaders (the panel's CSP + nonce).
Route::middleware([App\Http\Middleware\WidgetKeyFromPath::class, 'tenant:widget_key', 'tenant.state:outbound'])
    ->prefix('w')->group(function (): void {
        Route::get('{widget_key}.js', [App\Http\Controllers\Widget\WidgetShimController::class, 'script'])
            ->where('widget_key', '[A-Za-z0-9._-]+')->name('widget.shim');
        Route::get('panel/{widget_key}', [App\Http\Controllers\Widget\WidgetShimController::class, 'panel'])
            ->where('widget_key', '[A-Za-z0-9._-]+')->name('widget.panel');
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
    // Doc 11 §8 (S11 step 12): Backoffice → Organization (B5-S09 J01–J15). Reads under the manage
    // keys (§8's own audience); every mutation door reuses the step-3b writers, which audit themselves.
    Route::get('organization', [App\Http\Controllers\Admin\OrgScreensController::class, 'show'])
        ->middleware('permission:org.queues.manage')->name('org.screens.organization');
    Route::post('organization/departments/{departmentId}', [App\Http\Controllers\Admin\OrgScreensController::class, 'updateDepartment'])
        ->middleware(['permission:org.departments.manage', 'audit:org.department.updated'])->name('org.screens.department.update');
    Route::post('organization/departments/{departmentId}/reorder', [App\Http\Controllers\Admin\OrgScreensController::class, 'reorderDepartment'])
        ->middleware(['permission:org.departments.manage', 'audit:org.department.reordered'])->name('org.screens.department.reorder');
    Route::post('organization/departments/{departmentId}/access', [App\Http\Controllers\Admin\OrgScreensController::class, 'giveAccess'])
        ->middleware(['permission:org.departments.manage', 'audit:org.department.access_granted'])->name('org.screens.department.access');
    Route::post('organization/queues', [App\Http\Controllers\Admin\OrgScreensController::class, 'createQueue'])
        ->middleware(['permission:org.queues.manage', 'audit:org.queue.created'])->name('org.screens.queue.create');
    Route::post('organization/queues/{queueId}', [App\Http\Controllers\Admin\OrgScreensController::class, 'updateQueue'])
        ->middleware(['permission:org.queues.manage', 'audit:org.queue.updated'])->name('org.screens.queue.update');
    // add/remove are SEPARATE doors: each declares the exact audit action its writer records.
    Route::post('organization/queues/{queueId}/members/add', [App\Http\Controllers\Admin\OrgScreensController::class, 'queueMemberAdd'])
        ->middleware(['permission:org.queues.manage', 'audit:org.queue.member_added'])->name('org.screens.queue.member.add');
    Route::post('organization/queues/{queueId}/members/remove', [App\Http\Controllers\Admin\OrgScreensController::class, 'queueMemberRemove'])
        ->middleware(['permission:org.queues.manage', 'audit:org.queue.member_removed'])->name('org.screens.queue.member.remove');
    Route::post('organization/teams/{teamId}/members/add', [App\Http\Controllers\Admin\OrgScreensController::class, 'teamMemberAdd'])
        ->middleware(['permission:org.teams.manage', 'audit:org.team.member_added'])->name('org.screens.team.member.add');
    Route::post('organization/teams/{teamId}/members/remove', [App\Http\Controllers\Admin\OrgScreensController::class, 'teamMemberRemove'])
        ->middleware(['permission:org.teams.manage', 'audit:org.team.member_removed'])->name('org.screens.team.member.remove');
    // Doc 11 FR-18 / §8 (S11 step 13): the delete-wizard PAGES (B5-S10 J16–J21; data = the step-10
    // JSON doors) + the J17 queue deactivate door (QueueWriter::deactivate audits itself).
    Route::get('organization/queues/{queueId}/delete-wizard', [App\Http\Controllers\Admin\OrgScreensController::class, 'queueWizard'])
        ->middleware('permission:org.queues.manage')->name('org.screens.queue.wizard');
    Route::get('organization/departments/{departmentId}/delete-wizard', [App\Http\Controllers\Admin\OrgScreensController::class, 'departmentWizard'])
        ->middleware('permission:org.departments.manage')->name('org.screens.department.wizard');
    Route::post('organization/queues/{queueId}/deactivate', [App\Http\Controllers\Admin\OrgScreensController::class, 'deactivateQueue'])
        ->middleware(['permission:org.queues.manage', 'audit:org.queue.deactivated'])->name('org.screens.queue.deactivate');
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

// Doc 10 — the Knowledge Base doors (S10 step 3; JSON only until step 12's [B9] UI).
// Same staff chain; every route on a CLOSED kb.* key (six + delete); mutations
// declare their audit action or are exempted with a reason (autosave/locks are
// inert presence). INTERNAL articles are ABSENT for readers without kb.view_internal.
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('admin/kb')->group(function (): void {
    // S10 step 12 ([B9]): the staff surfaces — Inertia renders over the proven doors; no logic here.
    Route::get('/', [App\Http\Controllers\Kb\KbUiController::class, 'manager'])
        ->middleware('permission:tickets.view')->name('kb.ui.manager');
    Route::get('variants/{variantId}/edit', [App\Http\Controllers\Kb\KbUiController::class, 'editor'])
        ->middleware('permission:kb.articles.edit')->name('kb.ui.editor');
    Route::get('variants/{variantId}/history-page', [App\Http\Controllers\Kb\KbUiController::class, 'history'])
        ->middleware('permission:kb.articles.edit')->name('kb.ui.history');

    Route::get('categories', [App\Http\Controllers\Kb\KbCategoryController::class, 'index'])
        ->middleware('permission:tickets.view')->name('kb.categories.index'); // every staff member browses the tree
    Route::post('categories', [App\Http\Controllers\Kb\KbCategoryController::class, 'store'])
        ->middleware(['permission:kb.categories.manage', 'audit:kb.category.created'])->name('kb.categories.store');
    Route::patch('categories/{categoryId}', [App\Http\Controllers\Kb\KbCategoryController::class, 'update'])
        ->middleware(['permission:kb.categories.manage', 'audit:kb.category.updated'])->name('kb.categories.update');
    Route::get('categories/{categoryId}/impact', [App\Http\Controllers\Kb\KbCategoryController::class, 'impact'])
        ->middleware('permission:kb.categories.manage')->name('kb.categories.impact');
    Route::post('categories/{categoryId}/reassign', [App\Http\Controllers\Kb\KbCategoryController::class, 'reassign'])
        ->middleware(['permission:kb.categories.manage', 'audit:kb.category.reassigned'])->name('kb.categories.reassign');
    Route::delete('categories/{categoryId}', [App\Http\Controllers\Kb\KbCategoryController::class, 'destroy'])
        ->middleware(['permission:kb.categories.manage', 'audit:kb.category.deleted'])->name('kb.categories.destroy');

    Route::post('articles', [App\Http\Controllers\Kb\KbArticleController::class, 'store'])
        ->middleware(['permission:kb.articles.create', 'audit:kb.article.created'])->name('kb.articles.store');
    Route::delete('articles/{articleId}', [App\Http\Controllers\Kb\KbArticleController::class, 'destroy'])
        ->middleware(['permission:kb.articles.delete', 'audit:kb.article.deleted'])->name('kb.articles.destroy');
    Route::get('articles/{articleId}', [App\Http\Controllers\Kb\KbArticleController::class, 'show'])
        ->middleware('permission:tickets.view')->name('kb.articles.show');
    Route::post('articles/{articleId}/variants', [App\Http\Controllers\Kb\KbArticleController::class, 'addVariant'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.variant.created'])->name('kb.articles.variants.store');
    Route::post('articles/{articleId}/translate-all', [App\Http\Controllers\Kb\KbArticleController::class, 'translateAll'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.article.translate_all_requested'])->name('kb.articles.translate_all'); // §14 one click; FR-06's key
    // FR-17 staff search + §7 stats/feedback + FR-11 staff notes (step 10). Search is a READ (SEC-06: the query is stored nowhere).
    Route::get('search', [App\Http\Controllers\Kb\KbSearchController::class, 'index'])
        ->middleware('permission:tickets.view')->name('kb.search');
    Route::get('articles/{articleId}/stats', [App\Http\Controllers\Kb\KbFeedbackController::class, 'stats'])
        ->middleware('permission:kb.feedback.view')->name('kb.articles.stats');
    Route::get('articles/{articleId}/feedback', [App\Http\Controllers\Kb\KbFeedbackController::class, 'index'])
        ->middleware('permission:kb.feedback.view')->name('kb.articles.feedback');
    Route::post('variants/{variantId}/feedback/note', [App\Http\Controllers\Kb\KbFeedbackController::class, 'note'])
        ->middleware(['permission:tickets.view', 'audit:kb.feedback.noted'])->name('kb.feedback.note'); // FR-11: any staff member
    Route::post('feedback/{feedbackId}/action', [App\Http\Controllers\Kb\KbFeedbackController::class, 'action'])
        ->middleware(['permission:kb.feedback.view', 'audit:kb.feedback.actioned'])->name('kb.feedback.action');
    // FR-05/SEC-02 (step 11): article image upload — scan → store → signed editing URL; the
    // customer-facing path is the widget media door (public+published enforced there).
    Route::post('variants/{variantId}/media', [App\Http\Controllers\Kb\KbMediaController::class, 'store'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.article.media_uploaded'])->name('kb.media.store');
    // FR-06 AI tools (step 9): drafts are born here and finished on the ai queue — no publish path exists on these routes.
    Route::post('drafts/from-source', [App\Http\Controllers\Kb\KbAuthoringController::class, 'fromSource'])
        ->middleware(['permission:kb.articles.create', 'audit:kb.article.created'])->name('kb.drafts.from_source');
    Route::post('drafts/from-conversations', [App\Http\Controllers\Kb\KbAuthoringController::class, 'fromConversations'])
        ->middleware(['permission:kb.articles.create', 'audit:kb.article.created'])->name('kb.drafts.from_conversations'); // Doc 09 FR-20's one click (ids-only seam)
    Route::post('variants/{variantId}/ai/simplify', [App\Http\Controllers\Kb\KbAuthoringController::class, 'simplify'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.variant.ai_requested'])->name('kb.ai.simplify');
    Route::post('variants/{variantId}/ai/faq', [App\Http\Controllers\Kb\KbAuthoringController::class, 'faqVariants'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.variant.ai_requested'])->name('kb.ai.faq');
    Route::post('articles/{articleId}/move', [App\Http\Controllers\Kb\KbArticleController::class, 'move'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.article.moved'])->name('kb.articles.move');
    Route::patch('variants/{variantId}/meta', [App\Http\Controllers\Kb\KbArticleController::class, 'updateMeta'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.variant.updated'])->name('kb.variants.meta');

    Route::put('variants/{variantId}/working-copy', [App\Http\Controllers\Kb\KbEditorController::class, 'autosave'])
        ->middleware('permission:kb.articles.edit')->name('kb.editor.autosave');
    Route::get('variants/{variantId}/working-copy', [App\Http\Controllers\Kb\KbEditorController::class, 'recover'])
        ->middleware('permission:kb.articles.edit')->name('kb.editor.recover');
    Route::post('variants/{variantId}/lock', [App\Http\Controllers\Kb\KbEditorController::class, 'acquire'])
        ->middleware('permission:kb.articles.edit')->name('kb.editor.lock.acquire');
    Route::post('variants/{variantId}/lock/heartbeat', [App\Http\Controllers\Kb\KbEditorController::class, 'heartbeat'])
        ->middleware('permission:kb.articles.edit')->name('kb.editor.lock.heartbeat');
    Route::delete('variants/{variantId}/lock', [App\Http\Controllers\Kb\KbEditorController::class, 'release'])
        ->middleware('permission:kb.articles.edit')->name('kb.editor.lock.release');
    Route::post('variants/{variantId}/lock/takeover', [App\Http\Controllers\Kb\KbEditorController::class, 'takeover'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.lock.taken_over'])->name('kb.editor.lock.takeover');

    // S10 step 4 — workflow (FR-07), versions (FR-08), the review thread, the ONE visibility door (EC-11).
    Route::post('variants/{variantId}/submit', [App\Http\Controllers\Kb\KbWorkflowController::class, 'submit'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.variant.submitted'])->name('kb.workflow.submit');
    Route::post('variants/{variantId}/request-changes', [App\Http\Controllers\Kb\KbWorkflowController::class, 'requestChanges'])
        ->middleware(['permission:kb.articles.publish', 'audit:kb.variant.changes_requested'])->name('kb.workflow.request_changes');
    Route::post('variants/{variantId}/publish', [App\Http\Controllers\Kb\KbWorkflowController::class, 'publish'])
        ->middleware('permission:kb.articles.publish')->name('kb.workflow.publish'); // audits kb.variant.published OR self_published — the domain door writes it (exempt with reason)
    Route::post('variants/{variantId}/unpublish', [App\Http\Controllers\Kb\KbWorkflowController::class, 'unpublish'])
        ->middleware(['permission:kb.articles.publish', 'audit:kb.variant.unpublished'])->name('kb.workflow.unpublish');
    Route::post('variants/{variantId}/archive', [App\Http\Controllers\Kb\KbWorkflowController::class, 'archive'])
        ->middleware(['permission:kb.articles.publish', 'audit:kb.variant.archived'])->name('kb.workflow.archive');
    Route::post('variants/{variantId}/unarchive', [App\Http\Controllers\Kb\KbWorkflowController::class, 'unarchive'])
        ->middleware(['permission:kb.articles.publish', 'audit:kb.variant.unarchived'])->name('kb.workflow.unarchive');
    Route::post('articles/{articleId}/visibility', [App\Http\Controllers\Kb\KbWorkflowController::class, 'visibility'])
        ->middleware(['permission:kb.articles.publish', 'audit:kb.article.visibility_changed'])->name('kb.workflow.visibility');
    Route::get('variants/{variantId}/comments', [App\Http\Controllers\Kb\KbWorkflowController::class, 'comments'])
        ->middleware('permission:kb.articles.edit')->name('kb.review.comments');
    Route::post('variants/{variantId}/comments', [App\Http\Controllers\Kb\KbWorkflowController::class, 'comment'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.review.commented'])->name('kb.review.comment');
    Route::post('comments/{commentId}/resolve', [App\Http\Controllers\Kb\KbWorkflowController::class, 'resolveComment'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.review.resolved'])->name('kb.review.resolve');
    Route::get('variants/{variantId}/versions', [App\Http\Controllers\Kb\KbVersionController::class, 'history'])
        ->middleware('permission:kb.articles.edit')->name('kb.versions.history');
    Route::get('versions/{versionId}', [App\Http\Controllers\Kb\KbVersionController::class, 'show'])
        ->middleware('permission:kb.articles.edit')->name('kb.versions.show');
    Route::get('versions/{versionId}/diff', [App\Http\Controllers\Kb\KbVersionController::class, 'diff'])
        ->middleware('permission:kb.articles.edit')->name('kb.versions.diff');
    Route::post('versions/{versionId}/restore', [App\Http\Controllers\Kb\KbVersionController::class, 'restore'])
        ->middleware(['permission:kb.articles.edit', 'audit:kb.variant.restored'])->name('kb.versions.restore');

    // S10 step 7 — the tombstone/citation resolve doors (FR-16/T-06/EC-06/EC-07/SS-04; the widget/Telegram renderers consume the same resolvers).
    Route::get('cite/{articleId}/{versionId}', [App\Http\Controllers\Kb\KbResolveController::class, 'citation'])
        ->middleware('permission:tickets.view')->name('kb.resolve.citation');
    Route::get('resolve/{language}/{slug}', [App\Http\Controllers\Kb\KbResolveController::class, 'slug'])
        ->middleware('permission:tickets.view')->name('kb.resolve.slug');

    // S10 step 7b packet P3: the insert-as-link ACT (FR-14's own key gates the insert; the event, not the render).
    Route::post('insert-link', [App\Http\Controllers\Kb\KbInsertLinkController::class, 'store'])
        ->middleware('permission:ai.copilot.insert_draft')->name('kb.insert_link');
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
    // SESSION-F1 (B8-S05 E03): the flips drill-down — a READ of the same simulation (no audit, no mutation).
    Route::post('thresholds/flips', [App\Http\Controllers\Ai\AiBackofficeController::class, 'flips'])
        ->middleware('permission:ai.thresholds.edit')->name('ai.backoffice.thresholds.flips');
    Route::get('kill-switches', [App\Http\Controllers\Ai\AiBackofficeController::class, 'killSwitches'])
        ->middleware('permission:ai.killswitch')->name('ai.backoffice.killswitches');
    Route::post('kill-switches/flip', [App\Http\Controllers\Ai\AiBackofficeController::class, 'killSwitchFlip'])
        ->middleware('permission:ai.killswitch')->name('ai.backoffice.killswitches.flip');
    Route::get('banner', [App\Http\Controllers\Ai\AiBackofficeController::class, 'banner'])
        ->middleware('permission:tickets.view')->name('ai.backoffice.banner'); // AC-08: every staff member sees the banner
    Route::get('decisions', [App\Http\Controllers\Ai\AiBackofficeController::class, 'decisions'])
        ->middleware('permission:ai.decisions.view')->name('ai.backoffice.decisions');
    // SESSION-F1 (B8-S10, rulings Q2/Q3): the supervisor artefacts page — FR-17's outputs are decision rows (the
    // same key as the log); the RG queue inside it renders only for customers.rg_flag.view (J06 absence).
    Route::get('supervisor', [App\Http\Controllers\Ai\AiBackofficeController::class, 'supervisor'])
        ->middleware('permission:ai.decisions.view')->name('ai.backoffice.supervisor');
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
// Doc 11 FR-18 / AC-07 (S11 step 10): the restructure wizard doors — preview (inventory + computed confirmation),
// execute (validated live, queued as RestructureJob, org.restructure.submitted audited), progress (per-chunk row).
Route::middleware(['auth:staff', 'tenant:staff_session', 'tenant.state:staff'])->prefix('org')->group(function (): void {
    Route::get('queues/{queueId}/delete', [App\Http\Controllers\Org\RestructureController::class, 'previewQueue'])
        ->middleware('permission:org.queues.manage')->name('org.queue.delete.preview');
    Route::post('queues/{queueId}/delete', [App\Http\Controllers\Org\RestructureController::class, 'executeQueue'])
        ->middleware(['permission:org.queues.manage', 'audit:org.restructure.submitted'])->name('org.queue.delete');
    Route::get('departments/{departmentId}/delete', [App\Http\Controllers\Org\RestructureController::class, 'previewDepartment'])
        ->middleware('permission:org.departments.manage')->name('org.department.delete.preview');
    Route::post('departments/{departmentId}/delete', [App\Http\Controllers\Org\RestructureController::class, 'executeDepartment'])
        ->middleware(['permission:org.departments.manage', 'audit:org.restructure.submitted'])->name('org.department.delete');
    Route::get('restructure/{wizard}/{targetId}/progress', [App\Http\Controllers\Org\RestructureController::class, 'progress'])
        ->middleware('permission:org.queues.manage')->name('org.restructure.progress');
});

// Doc 11 T-06 / SEC-05 (S11 step 8): the cached estimate door — bands only.
Route::middleware(['auth:staff', 'tenant:staff_session', 'tenant.state:staff'])
    ->get('internal/routing/estimate/{queueId}', [App\Http\Controllers\Routing\EstimateController::class, 'show'])
    ->middleware('permission:tickets.view')->name('internal.routing.estimate');

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
    // Doc 11 FR-17/SEC-02 (S11 step 5): the routing-record read door (elision by role inside RecordReader).
    Route::get('t/{ticketId}/routing/{recordId}', [App\Http\Controllers\Routing\RoutingRecordController::class, 'show'])
        ->middleware('permission:tickets.view')->name('inbox.ticket.routing_record');
    // Doc 11 FR-15 / SEC-01 (S11 step 7): the supervisor doors — reassign (bypasses strategy,
    // attributed), pull-in (Doc 03 grant through OverrideAdministration, SEC-03), its manual
    // end, broadcast ([D16] contract-only). Force status is Doc 07's door above (inbox.status.force).
    Route::post('t/{ticketId}/reassign', [App\Http\Controllers\Routing\SupervisorOpsController::class, 'reassign'])
        ->middleware(['permission:supervision.reassign', 'audit:routing.manual_reassign'])->name('inbox.ticket.reassign');
    Route::post('queues/{queueId}/pull-in', [App\Http\Controllers\Routing\SupervisorOpsController::class, 'pullIn'])
        ->middleware(['permission:org.queues.pull_in', 'audit:routing.pull_in.created'])->name('inbox.queue.pull_in');
    Route::post('queues/{queueId}/pull-in/{userId}/end', [App\Http\Controllers\Routing\SupervisorOpsController::class, 'endPullIn'])
        ->middleware(['permission:org.queues.pull_in', 'audit:routing.pull_in.ended'])->name('inbox.queue.pull_in.end');
    Route::post('queues/{queueId}/broadcast', [App\Http\Controllers\Routing\SupervisorOpsController::class, 'broadcast'])
        ->middleware(['permission:supervision.broadcast', 'audit:routing.broadcast_sent'])->name('inbox.queue.broadcast');
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

// ── Doc 12 §8/§9 (S12 step 14): Backoffice → Workflow — Doc 12's OWN surfaces, functional/themed/
// i18n-keyed from design-system components, riding [B12-batch] (R-D12-2.2). Reads under the view
// keys, mutations under the edit/activate/publish/manage keys — the catalog's own Doc 12 entries
// (CLOSED at 114; no key invented). Every mutation door is a step-4…13 writer that audits itself;
// the route declares the same key (the D8/A-37 lesson at design time).
Route::middleware([
    'auth:staff',
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('admin/workflow')->group(function (): void {
    $w = App\Http\Controllers\Workflow\WorkflowScreensController::class;
    Route::get('/', [$w, 'hub'])->middleware('permission:automation.rules.view')->name('workflow.hub');

    // Rules (FR-01…FR-07, §8 rules list + editor + dry-run drawer)
    Route::get('rules', [$w, 'rules'])->middleware('permission:automation.rules.view')->name('workflow.rules');
    // The dry-run door is registered BEFORE the {ruleId} routes (a literal segment must never be captured as an id).
    Route::post('rules/dry-run', [$w, 'dryRun'])->middleware('permission:automation.rules.edit')->name('workflow.rules.dry_run');
    Route::get('rules/{ruleId}', [$w, 'ruleEditor'])->middleware('permission:automation.rules.view')->name('workflow.rules.editor');
    // SESSION-F1 [B12] B9-S13 M02 — the execution detail READ page (FR-06 / SEC-03) on the existing key
    Route::get('rules/{ruleId}/executions/{executionId}', [$w, 'execution'])->middleware('permission:automation.rules.view')->name('workflow.rules.execution');
    Route::post('rules', [$w, 'createRule'])->middleware(['permission:automation.rules.edit', 'audit:automation.rule.created'])->name('workflow.rules.create');
    Route::post('rules/{ruleId}', [$w, 'updateRule'])->middleware(['permission:automation.rules.edit', 'audit:automation.rule.updated'])->name('workflow.rules.update');
    Route::post('rules/{ruleId}/activate', [$w, 'activateRule'])->middleware(['permission:automation.rules.activate', 'audit:automation.rule.activated'])->name('workflow.rules.activate');
    Route::post('rules/{ruleId}/deactivate', [$w, 'deactivateRule'])->middleware(['permission:automation.rules.activate', 'audit:automation.rule.deactivated'])->name('workflow.rules.deactivate');
    Route::post('rules/{ruleId}/reorder', [$w, 'reorderRule'])->middleware(['permission:automation.rules.edit', 'audit:automation.rule.updated'])->name('workflow.rules.reorder');
    Route::post('keyword-sets', [$w, 'createKeywordSet'])->middleware(['permission:automation.rules.edit', 'audit:automation.keyword_set.changed'])->name('workflow.keyword_sets.create');
    Route::post('keyword-sets/{setId}', [$w, 'updateKeywordSet'])->middleware(['permission:automation.rules.edit', 'audit:automation.keyword_set.changed'])->name('workflow.keyword_sets.update');
    Route::post('webhook-destinations', [$w, 'createDestination'])->middleware(['permission:automation.rules.edit', 'audit:automation.webhook_destination.changed'])->name('workflow.destinations.create');
    Route::post('webhook-destinations/{destinationId}', [$w, 'updateDestination'])->middleware(['permission:automation.rules.edit', 'audit:automation.webhook_destination.changed'])->name('workflow.destinations.update');

    // SLA policies (FR-08…FR-11, §8 live example)
    Route::get('sla', [$w, 'sla'])->middleware('permission:sla.policies.view')->name('workflow.sla');
    Route::post('sla/policies', [$w, 'createPolicy'])->middleware(['permission:sla.policies.edit', 'audit:sla.policy.changed'])->name('workflow.sla.create');
    Route::post('sla/policies/{policyId}', [$w, 'updatePolicy'])->middleware(['permission:sla.policies.edit', 'audit:sla.policy.changed'])->name('workflow.sla.update');
    Route::post('sla/example', [$w, 'slaExample'])->middleware('permission:sla.policies.edit')->name('workflow.sla.example');

    // Business hours (FR-12/FR-13, §8 painter + interval-list editor)
    Route::get('hours', [$w, 'hours'])->middleware('permission:sla.policies.view')->name('workflow.hours');
    Route::post('hours/calendars', [$w, 'createCalendar'])->middleware(['permission:sla.policies.edit', 'audit:hours.calendar.updated'])->name('workflow.hours.create');
    Route::post('hours/calendars/{calendarId}', [$w, 'updateCalendar'])->middleware(['permission:sla.policies.edit', 'audit:hours.calendar.updated'])->name('workflow.hours.update');
    Route::post('hours/calendars/{calendarId}/holidays', [$w, 'addHoliday'])->middleware(['permission:sla.policies.edit', 'audit:hours.calendar.updated'])->name('workflow.hours.holiday.add');
    Route::post('hours/holidays/{holidayId}/remove', [$w, 'removeHoliday'])->middleware(['permission:sla.policies.edit', 'audit:hours.calendar.updated'])->name('workflow.hours.holiday.remove');

    // Macros (FR-14…FR-16, §8 manager + editor with live preview)
    Route::get('macros', [$w, 'macros'])->middleware('permission:macros.personal.manage')->name('workflow.macros');
    Route::post('macros', [$w, 'createMacro'])->middleware(['permission:macros.personal.manage', 'audit:macro.changed'])->name('workflow.macros.create');
    Route::post('macros/{macroId}', [$w, 'updateMacro'])->middleware(['permission:macros.personal.manage', 'audit:macro.changed'])->name('workflow.macros.update');
    Route::post('macros/{macroId}/preview', [$w, 'previewMacro'])->middleware('permission:macros.personal.manage')->name('workflow.macros.preview');

    // Fields (FR-17…FR-19, §8 manager + where-used)
    Route::get('fields', [$w, 'fields'])->middleware('permission:fields.manage')->name('workflow.fields');
    Route::post('fields', [$w, 'createField'])->middleware(['permission:fields.manage', 'audit:field.changed'])->name('workflow.fields.create');
    Route::post('fields/{fieldId}', [$w, 'updateField'])->middleware(['permission:fields.manage', 'audit:field.changed'])->name('workflow.fields.update');
    Route::post('fields/{fieldId}/deactivate', [$w, 'deactivateField'])->middleware(['permission:fields.manage', 'audit:field.deactivated'])->name('workflow.fields.deactivate');
    Route::post('fields/{fieldId}/reactivate', [$w, 'reactivateField'])->middleware(['permission:fields.manage', 'audit:field.changed'])->name('workflow.fields.reactivate');
    Route::get('fields/{entity}/{key}/where-used', [$w, 'whereUsed'])->middleware('permission:fields.manage')->name('workflow.fields.where_used');

    // Forms (FR-20…FR-23, §8 builder with dual live preview)
    Route::get('forms', [$w, 'forms'])->middleware('permission:forms.view')->name('workflow.forms');
    Route::get('forms/{formId}', [$w, 'formBuilder'])->middleware('permission:forms.view')->name('workflow.forms.builder');
    Route::post('forms', [$w, 'createForm'])->middleware(['permission:forms.edit', 'audit:form.changed'])->name('workflow.forms.create');
    Route::post('forms/{formId}', [$w, 'updateForm'])->middleware(['permission:forms.edit', 'audit:form.changed'])->name('workflow.forms.update');
    Route::post('forms/{formId}/preview', [$w, 'previewForm'])->middleware('permission:forms.edit')->name('workflow.forms.preview');
    Route::post('forms/{formId}/publish', [$w, 'publishForm'])->middleware(['permission:forms.publish', 'audit:form.published'])->name('workflow.forms.publish');

    // CSAT settings (FR-24/FR-25, §8 settings page)
    Route::get('csat', [$w, 'csat'])->middleware('permission:settings.view')->name('workflow.csat');
    Route::post('csat', [$w, 'updateCsat'])->middleware(['permission:settings.edit', 'audit:tenant.setting_changed'])->name('workflow.csat.update');
});
