<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionDefinition;

/**
 * The nine seeded role templates — Appendix B as code (Doc 03 FR-10; owner
 * ruling R-10 ratified the exact grants; docs/Doc03-AppendixB-role-templates.md
 * is the human-readable twin and T-05(c)'s drift test pins all three layers
 * together: this file, the appendix's constraints, and what the seeder wrote).
 *
 * Colors are design-token NAMES (the roles-list dot, B6-S07); N-03 forbids raw
 * hexes in stored appearance. The token names are reconciled against the
 * encoded design system in step 9 before any UI renders them.
 *
 * Names and descriptions are seeded CONTENT (operator-editable free text per
 * §14), not i18n keys — the operator owns them from the first edit.
 */
final class RoleTemplates
{
    public const SUPER_ADMIN = 'Super Admin';

    /** Names in seeding order. */
    public const NAMES = [
        'Super Admin', 'Admin', 'Manager', 'Team Leader', 'Senior Agent',
        'Agent', 'Junior Agent', 'QA', 'Read Only',
    ];

    private const ALL_SCOPES = ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All'];

    private const DEPT_SCOPES = ['tickets' => 'Dept', 'conversations' => 'Dept', 'customers' => 'Dept', 'reports' => 'Dept'];

    private const TEAM_SCOPES = ['tickets' => 'Team', 'conversations' => 'Team', 'customers' => 'Team', 'reports' => 'Team'];

    private const OWN_SCOPES = ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own'];

    /**
     * @return array<string, array{description: string, color: string, permissions: list<string>, scopes: array<string, string>}>
     */
    public function all(PermissionCatalog $catalog): array
    {
        $everything = array_keys($catalog->all());

        // BR-06 + ruling R-10: hard destruction and RG access stay explicit
        // acts — Super Admin alone carries them out of the box.
        $admin = array_values(array_diff($everything, [
            'tickets.delete', 'customers.delete', 'customers.rg_flag.view', 'customers.rg_flag.set',
        ]));

        // BR-05 read strictly (ruling R-10): action segment `view`, risk
        // normal — computed from the catalog so a future catalog edit that
        // breaks the rule fails the pinned test, not silently widens the
        // template.
        $readOnly = array_keys(array_filter(
            $catalog->all(),
            fn (PermissionDefinition $definition) => ! $definition->high
                && str_ends_with($definition->key, '.view'),
        ));

        return [
            'Super Admin' => [
                'description' => 'Everything, everywhere. The bootstrap owner role.',
                'color' => 'role-super-admin',
                'permissions' => $everything,
                'scopes' => self::ALL_SCOPES,
            ],
            'Admin' => [
                'description' => 'Full administration except hard deletion and responsible-gambling access.',
                'color' => 'role-admin',
                'permissions' => $admin,
                'scopes' => self::ALL_SCOPES,
            ],
            'Manager' => [
                'description' => 'Runs a department end to end: full ticket operations, supervision, reports.',
                'color' => 'role-manager',
                'permissions' => [
                    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply', 'tickets.note',
                    'tickets.assign', 'tickets.assign_self', 'tickets.status.change', 'tickets.priority.change',
                    'tickets.department.change', 'tickets.close', 'tickets.reopen', 'tickets.merge',
                    'tickets.split', 'tickets.escalate', 'tickets.follow', 'tickets.tags.manage',
                    'tickets.fields.edit', 'tickets.spam.mark', 'tickets.export',
                    'conversations.view', 'conversations.history.full',
                    'customers.view', 'customers.create', 'customers.edit', 'customers.notes.add',
                    'customers.notes.view', 'customers.identity.link', 'customers.block',
                    'telegram.send', 'telegram.message.delete', 'telegram.bots.view', 'telegram.health.view',
                    'webchat.respond', 'webchat.widgets.view',
                    'sms.send', 'sms.templates.view', 'sms.templates.create', 'sms.templates.edit', 'sms.optout.manage',
                    'ai.copilot.use', 'ai.copilot.insert_draft', 'ai.decisions.view',
                    'kb.view_internal', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.publish',
                    'kb.categories.manage', 'kb.feedback.view',
                    'automation.rules.view', 'automation.rules.edit', 'macros.personal.manage',
                    'macros.shared.manage', 'forms.view', 'forms.edit', 'sla.policies.view',
                    'org.teams.manage', 'org.queues.manage', 'org.queues.pull_in', 'org.business_hours.edit',
                    'admin.users.view', 'admin.roles.view',
                    'reports.view', 'reports.dashboards.create', 'reports.dashboards.share', 'reports.export',
                    'reports.schedule', 'reports.supervisor.live', 'reports.agents.individual',
                    'supervision.monitor', 'supervision.whisper', 'supervision.reassign',
                    'supervision.agent_status.force', 'supervision.broadcast',
                    'settings.view',
                ],
                'scopes' => self::DEPT_SCOPES,
            ],
            'Team Leader' => [
                'description' => 'Reassigns and monitors within the department; no settings access.',
                'color' => 'role-team-leader',
                'permissions' => [
                    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply', 'tickets.note',
                    'tickets.assign', 'tickets.assign_self', 'tickets.status.change', 'tickets.priority.change',
                    'tickets.department.change', 'tickets.close', 'tickets.reopen', 'tickets.merge',
                    'tickets.split', 'tickets.escalate', 'tickets.follow', 'tickets.tags.manage',
                    'tickets.fields.edit', 'tickets.spam.mark',
                    'conversations.view', 'conversations.history.full',
                    'customers.view', 'customers.create', 'customers.edit', 'customers.notes.add',
                    'customers.notes.view', 'customers.block',
                    'telegram.send', 'webchat.respond',
                    'sms.send', 'sms.templates.view',
                    'ai.copilot.use', 'ai.copilot.insert_draft', 'ai.decisions.view',
                    'kb.view_internal', 'kb.feedback.view',
                    'automation.rules.view', 'macros.personal.manage', 'macros.shared.manage',
                    'forms.view', 'sla.policies.view',
                    'org.queues.pull_in',
                    'admin.users.view',
                    'reports.view', 'reports.dashboards.create', 'reports.supervisor.live',
                    'supervision.monitor', 'supervision.reassign', 'supervision.broadcast',
                ],
                'scopes' => self::DEPT_SCOPES,
            ],
            'Senior Agent' => [
                'description' => 'Full ticket handling across the team, knowledge base authoring.',
                'color' => 'role-senior-agent',
                'permissions' => [
                    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply', 'tickets.note',
                    'tickets.assign', 'tickets.assign_self', 'tickets.status.change', 'tickets.priority.change',
                    'tickets.close', 'tickets.reopen', 'tickets.merge', 'tickets.split', 'tickets.escalate',
                    'tickets.follow', 'tickets.tags.manage', 'tickets.fields.edit', 'tickets.spam.mark',
                    'conversations.view', 'conversations.history.full',
                    'customers.view', 'customers.create', 'customers.edit', 'customers.notes.add',
                    'customers.notes.view', 'customers.identity.link',
                    'telegram.send', 'webchat.respond',
                    'sms.templates.view',
                    'ai.copilot.use', 'ai.copilot.insert_draft',
                    'kb.view_internal', 'kb.articles.create', 'kb.articles.edit', 'kb.feedback.view',
                    'macros.personal.manage', 'forms.view',
                    'org.queues.pull_in',
                    'reports.view',
                ],
                'scopes' => self::TEAM_SCOPES,
            ],
            'Agent' => [
                'description' => 'Handles own tickets and customers day to day.',
                'color' => 'role-agent',
                'permissions' => [
                    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply', 'tickets.note',
                    'tickets.assign_self', 'tickets.status.change', 'tickets.close', 'tickets.reopen',
                    'tickets.escalate', 'tickets.follow', 'tickets.fields.edit', 'tickets.spam.mark',
                    'conversations.view',
                    'customers.view', 'customers.create', 'customers.notes.add', 'customers.notes.view',
                    'telegram.send', 'webchat.respond',
                    'ai.copilot.use', 'ai.copilot.insert_draft',
                    'kb.view_internal',
                    'macros.personal.manage', 'forms.view',
                ],
                'scopes' => self::OWN_SCOPES,
            ],
            'Junior Agent' => [
                'description' => 'Replies on own tickets under supervision; no state changes beyond self-assign.',
                'color' => 'role-junior-agent',
                'permissions' => [
                    'tickets.view', 'tickets.create', 'tickets.reply', 'tickets.note',
                    'tickets.assign_self', 'tickets.follow',
                    'conversations.view',
                    'customers.view', 'customers.notes.add', 'customers.notes.view',
                    'telegram.send', 'webchat.respond',
                    'ai.copilot.use',
                    'kb.view_internal',
                    'macros.personal.manage',
                ],
                'scopes' => self::OWN_SCOPES,
            ],
            'QA' => [
                'description' => 'Reads everything to score quality; every reply and edit control is absent.',
                'color' => 'role-qa',
                'permissions' => [
                    'tickets.view', 'conversations.view', 'conversations.history.full',
                    'customers.view', 'customers.notes.view',
                    'ai.decisions.view', 'kb.view_internal', 'kb.feedback.view',
                    'reports.view',
                ],
                'scopes' => self::ALL_SCOPES,
            ],
            'Read Only' => [
                'description' => 'Sees, never touches. Exclusively non-high .view keys (BR-05).',
                'color' => 'role-read-only',
                'permissions' => $readOnly,
                'scopes' => self::ALL_SCOPES,
            ],
        ];
    }
}
