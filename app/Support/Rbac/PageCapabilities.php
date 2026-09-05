<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;

/**
 * T-04: the UI capability payload is MINIMAL — each Inertia page receives
 * only the keys it actually uses, computed from this manifest, never the
 * full matrix. Adding a capability to a page means adding it HERE, which is
 * what keeps the payload auditable (the test pins payload ⊆ manifest).
 */
final class PageCapabilities
{
    /** @var array<string, list<string>> page component → the keys it renders against */
    public const MANIFEST = [
        'Admin/Rbac/RolesList' => [
            'admin.roles.view', 'admin.roles.manage', 'admin.users.manage',
        ],
        'Admin/Rbac/RoleEditor' => [
            'admin.roles.view', 'admin.roles.manage',
        ],
        'Admin/Rbac/UserOverrides' => [
            'admin.users.view', 'admin.overrides.manage',
        ],
        'Admin/Rbac/PendingGrants' => [
            'admin.roles.view', 'admin.roles.manage', 'admin.overrides.manage',
        ],
        // Doc 04 FS-05 (R-17 class-2 fill): the re-drive button renders only
        // for holders of the key that gates the re-drive route (A-6).
        // Doc 11 §8 (S11 step 12): Backoffice → Organization — the keys its controls render against.
        'Admin/Org/Organization' => [
            'org.departments.manage', 'org.teams.manage', 'org.queues.manage', 'org.queues.pull_in',
        ],
        // Doc 11 FR-18 (S11 step 13): the delete wizards — both manage keys can reach a wizard page.
        'Admin/Org/RestructureWizard' => [
            'org.departments.manage', 'org.queues.manage',
        ],
        // Doc 12 §8 (S12 step 14): Backoffice → Workflow — each page's controls render against these keys.
        'Workflow/Hub' => ['automation.rules.view', 'sla.policies.view', 'forms.view', 'fields.manage', 'macros.personal.manage', 'settings.view'],
        'Workflow/Rules' => ['automation.rules.view', 'automation.rules.edit', 'automation.rules.activate'],
        'Workflow/RuleEditor' => ['automation.rules.view', 'automation.rules.edit', 'automation.rules.activate'],
        'Workflow/Sla' => ['sla.policies.view', 'sla.policies.edit'],
        'Workflow/Hours' => ['sla.policies.view', 'sla.policies.edit'],
        'Workflow/Macros' => ['macros.personal.manage', 'macros.shared.manage'],
        'Workflow/Fields' => ['fields.manage'],
        'Workflow/Forms' => ['forms.view', 'forms.edit', 'forms.publish'],
        'Workflow/FormBuilder' => ['forms.view', 'forms.edit', 'forms.publish'],
        'Workflow/Csat' => ['settings.view', 'settings.edit'],
        // Doc 13 §4.4 (S13 step 11): the Widget Designer — reads under view, every editor control under configure.
        'Admin/Widgets/Index' => ['webchat.widgets.view', 'webchat.widgets.configure'],
        'Admin/Widgets/Editor' => ['webchat.widgets.view', 'webchat.widgets.configure'],
        'Conversations/DeadLetters' => [
            'tickets.reply',
        ],
    ];

    public function __construct(private readonly CapabilityMapProvider $maps) {}

    /**
     * @return array<string, bool> only the manifest's keys for this page
     */
    public function for(User $user, string $page): array
    {
        $map = $this->maps->mapFor($user);

        $can = [];
        foreach (self::MANIFEST[$page] ?? [] as $key) {
            $can[$key] = $map->allows($key);
        }

        return $can;
    }
}
