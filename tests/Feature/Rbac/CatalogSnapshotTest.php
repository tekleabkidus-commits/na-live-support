<?php

declare(strict_types=1);

use App\Support\Rbac\PermissionCatalog;

/**
 * T-05(b) — the catalog snapshot test (Doc 03 NFR-03, BR-03, FR-01; ruling R-12).
 *
 * The lists below are LITERAL, transcribed from Doc 03 §4.1 — they are the pin,
 * not a re-read of the registry. Deleting, renaming or adding a key in
 * registries/permissions.php without editing this test in the same commit is a
 * red build, which is exactly NFR-03's contract: keys are never renamed or
 * repurposed; deprecation = new key + two-release migration; additions require
 * doc-owner sign-off (Doc 01 R-03).
 *
 * Counts on the record (R-12): 111 keys, 38 marked "(high)", 46 scoped.
 */

/** Doc 03 §4.1, all fourteen modules, catalog order. */
const DOC03_CATALOG_KEYS = [
    // Tickets (scoped)
    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply',
    'tickets.note', 'tickets.assign', 'tickets.assign_self', 'tickets.status.change',
    'tickets.priority.change', 'tickets.department.change', 'tickets.close', 'tickets.reopen',
    'tickets.merge', 'tickets.split', 'tickets.escalate', 'tickets.follow',
    'tickets.tags.manage', 'tickets.fields.edit', 'tickets.spam.mark', 'tickets.delete',
    'tickets.export',
    // Conversations (scoped)
    'conversations.view', 'conversations.history.full', 'conversations.transcript.export',
    // Customers (scoped)
    'customers.view', 'customers.create', 'customers.edit', 'customers.notes.add',
    'customers.notes.view', 'customers.identity.link', 'customers.identity.unlink', 'customers.merge',
    'customers.block', 'customers.rg_flag.view', 'customers.rg_flag.set', 'customers.export',
    'customers.delete',
    // Telegram (unscoped module admin + scoped messaging)
    'telegram.send', 'telegram.message.delete', 'telegram.bots.view', 'telegram.bots.manage',
    'telegram.connections.manage', 'telegram.health.view',
    // Web chat
    'webchat.respond', 'webchat.widgets.view', 'webchat.widgets.configure',
    // SMS
    'sms.send', 'sms.templates.view', 'sms.templates.create', 'sms.templates.edit',
    'sms.templates.delete', 'sms.gateways.view', 'sms.gateways.manage', 'sms.optout.manage',
    // AI
    'ai.copilot.use', 'ai.copilot.insert_draft', 'ai.chatbot.configure', 'ai.guardrails.edit',
    'ai.thresholds.edit', 'ai.decisions.view', 'ai.killswitch',
    // Knowledge Base
    'kb.view_internal', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.delete',
    'kb.articles.publish', 'kb.categories.manage', 'kb.feedback.view',
    // Automation & Workflow
    'automation.rules.view', 'automation.rules.edit', 'automation.rules.activate',
    'macros.personal.manage', 'macros.shared.manage', 'forms.view', 'forms.edit',
    'forms.publish', 'fields.manage', 'sla.policies.view', 'sla.policies.edit',
    // Organization
    'org.departments.manage', 'org.teams.manage', 'org.queues.manage', 'org.queues.pull_in',
    'org.business_hours.edit',
    // Users & Roles
    'admin.users.view', 'admin.users.manage', 'admin.users.disable', 'admin.roles.view',
    'admin.roles.manage', 'admin.overrides.manage', 'admin.2fa.reset',
    // Reports (scope applies to data visibility)
    'reports.view', 'reports.dashboards.create', 'reports.dashboards.share', 'reports.export',
    'reports.schedule', 'reports.supervisor.live', 'reports.agents.individual',
    // Supervision
    'supervision.monitor', 'supervision.whisper', 'supervision.reassign',
    'supervision.agent_status.force', 'supervision.broadcast',
    // Platform settings
    'settings.view', 'settings.edit', 'branding.edit', 'features.toggle',
    'languages.manage', 'audit.view', 'audit.export', 'apikeys.machine.manage',
];

/** Every key §4.1 marks "(high)" — counted from the text, twice (R-12). */
const DOC03_HIGH_KEYS = [
    'tickets.delete', 'tickets.export',
    'conversations.transcript.export',
    'customers.identity.unlink', 'customers.merge', 'customers.rg_flag.view',
    'customers.rg_flag.set', 'customers.export', 'customers.delete',
    'telegram.bots.manage', 'telegram.connections.manage',
    'webchat.widgets.configure',
    'sms.gateways.manage',
    'ai.chatbot.configure', 'ai.guardrails.edit', 'ai.thresholds.edit', 'ai.killswitch',
    'automation.rules.activate', 'forms.publish', 'fields.manage', 'sla.policies.edit',
    'org.departments.manage',
    'admin.users.manage', 'admin.users.disable', 'admin.roles.manage',
    'admin.overrides.manage', 'admin.2fa.reset',
    'reports.export', 'reports.agents.individual',
    'supervision.monitor', 'supervision.whisper', 'supervision.agent_status.force',
    'settings.edit', 'branding.edit', 'features.toggle', 'audit.view',
    'audit.export', 'apikeys.machine.manage',
];

/**
 * Scoped keys: §4.1's module headers — Tickets, Conversations, Customers all
 * scoped; Telegram/Web chat scoped for messaging only ("scoped via tickets");
 * Reports' scope applies to data visibility. Everything else is unscoped.
 */
const DOC03_SCOPED_KEYS = [
    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply',
    'tickets.note', 'tickets.assign', 'tickets.assign_self', 'tickets.status.change',
    'tickets.priority.change', 'tickets.department.change', 'tickets.close', 'tickets.reopen',
    'tickets.merge', 'tickets.split', 'tickets.escalate', 'tickets.follow',
    'tickets.tags.manage', 'tickets.fields.edit', 'tickets.spam.mark', 'tickets.delete',
    'tickets.export',
    'conversations.view', 'conversations.history.full', 'conversations.transcript.export',
    'customers.view', 'customers.create', 'customers.edit', 'customers.notes.add',
    'customers.notes.view', 'customers.identity.link', 'customers.identity.unlink', 'customers.merge',
    'customers.block', 'customers.rg_flag.view', 'customers.rg_flag.set', 'customers.export',
    'customers.delete',
    'telegram.send', 'webchat.respond',
    'reports.view', 'reports.dashboards.create', 'reports.dashboards.share', 'reports.export',
    'reports.schedule', 'reports.supervisor.live', 'reports.agents.individual',
];

it('registers EXACTLY the 111 keys Doc 03 §4.1 enumerates — verbatim, nothing else (Q9, R-12)', function () {
    $registered = array_keys(app(PermissionCatalog::class)->all());

    expect($registered)->toBe(DOC03_CATALOG_KEYS)
        ->and(count($registered))->toBe(111);
});

it('marks high-risk exactly where §4.1 writes "(high)" — all 38, no more, no fewer (R-12)', function () {
    expect(app(PermissionCatalog::class)->highKeys())->toBe(DOC03_HIGH_KEYS)
        ->and(count(DOC03_HIGH_KEYS))->toBe(38);
});

it('pins the scope dimension of every key: 46 scoped per the §4.1 module headers, the rest unscoped', function () {
    $catalog = app(PermissionCatalog::class);

    $scoped = array_values(array_keys(array_filter(
        $catalog->all(),
        fn ($definition) => $definition->scoped,
    )));

    expect($scoped)->toBe(DOC03_SCOPED_KEYS)
        ->and(count(DOC03_SCOPED_KEYS))->toBe(46);
});

it('keeps the four Doc 02-era entries under their exact registered names (NFR-03 — never renamed)', function () {
    $catalog = app(PermissionCatalog::class);

    foreach (['settings.view', 'settings.edit', 'admin.2fa.reset', 'apikeys.machine.manage'] as $key) {
        expect($catalog->has($key))->toBeTrue()
            ->and($catalog->get($key)->labelI18nKey)->toBe('perm.'.$key);
    }
});

it('resolves every label and description i18n key in BOTH launch languages (FR-01, §14, C-08)', function () {
    foreach (app(PermissionCatalog::class)->all() as $definition) {
        foreach (['en', 'am'] as $locale) {
            expect(__($definition->labelI18nKey, [], $locale))
                ->not->toBe($definition->labelI18nKey, "missing {$locale} label for {$definition->key}")
                ->and(__($definition->descriptionI18nKey, [], $locale))
                ->not->toBe($definition->descriptionI18nKey, "missing {$locale} description for {$definition->key}");
        }
    }
});

it('groups every key under one of the fourteen §4.1 modules, each with a localized name', function () {
    $byModule = app(PermissionCatalog::class)->byModule();

    expect(array_keys($byModule))->toBe([
        'tickets', 'conversations', 'customers', 'telegram', 'webchat', 'sms',
        'ai', 'kb', 'automation', 'organization', 'users_roles', 'reports',
        'supervision', 'platform_settings',
    ]);

    foreach (array_keys($byModule) as $module) {
        foreach (['en', 'am'] as $locale) {
            expect(__('perm_module.'.$module, [], $locale))
                ->not->toBe('perm_module.'.$module, "missing {$locale} name for module {$module}");
        }
    }
});

it('treats an unknown key as a programming error, never a verdict (BR-03 — the catalog is closed)', function () {
    app(PermissionCatalog::class)->get('tickets.read');
})->throws(LogicException::class, 'tickets.read');
