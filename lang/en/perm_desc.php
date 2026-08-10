<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Permission descriptions — English (Doc 03 FR-01, §14; C-08; N-03)
|------------------------------------------------------------------------------
|
| One entry per catalog key in registries/permissions.php; CatalogSnapshotTest
| asserts completeness in BOTH locales. Amharic is machine-authored and queued
| for native review (standing queue; ruling R-14 flags the destructive tier).
|
*/

return [
    'tickets' => [
        'view' => 'See tickets within the role\'s scope.',
        'create' => 'Open a new ticket on a customer\'s behalf.',
        'edit' => 'Change a ticket\'s subject and details.',
        'reply' => 'Send replies to the customer on a ticket.',
        'note' => 'Write internal notes visible to staff only — never to the customer.',
        'assign' => 'Assign a ticket to another agent.',
        'assign_self' => 'Take an unassigned ticket for yourself.',
        'status' => [
            'change' => 'Move a ticket between statuses.',
        ],
        'priority' => [
            'change' => 'Raise or lower a ticket\'s priority.',
        ],
        'department' => [
            'change' => 'Transfer a ticket to another department.',
        ],
        'close' => 'Close a resolved ticket.',
        'reopen' => 'Reopen a closed ticket.',
        'merge' => 'Combine duplicate tickets into one.',
        'split' => 'Split one ticket into separate tickets.',
        'escalate' => 'Escalate a ticket to a higher tier.',
        'follow' => 'Follow a ticket to get its updates.',
        'tags' => [
            'manage' => 'Add and remove tags on tickets.',
        ],
        'fields' => [
            'edit' => 'Change custom field values on a ticket.',
        ],
        'spam' => [
            'mark' => 'Mark a ticket as spam so it never counts in metrics.',
        ],
        'delete' => 'Delete a ticket — platform-disabled in v1; the erasure workflow is the only path.',
        'export' => 'Export tickets out of the platform.',
    ],
    'conversations' => [
        'view' => 'Read conversations within the role\'s scope.',
        'history' => [
            'full' => 'See a conversation\'s complete history instead of the recent window.',
        ],
        'transcript' => [
            'export' => 'Export conversation transcripts out of the platform.',
        ],
    ],
    'customers' => [
        'view' => 'See customer profiles within the role\'s scope.',
        'create' => 'Create a new customer profile.',
        'edit' => 'Change staff-collected customer fields.',
        'notes' => [
            'add' => 'Write internal notes on a customer profile.',
            'view' => 'Read internal notes on a customer profile.',
        ],
        'identity' => [
            'link' => 'Link a channel identity to a customer profile.',
            'unlink' => 'Detach a channel identity from a customer profile.',
        ],
        'merge' => 'Merge two customer profiles into one.',
        'block' => 'Block a customer from opening new conversations.',
        'rg_flag' => [
            'view' => 'See a customer\'s responsible-gambling flag.',
            'set' => 'Set or clear a customer\'s responsible-gambling flag.',
        ],
        'export' => 'Export customer data out of the platform.',
        'delete' => 'Delete a customer profile — the erasure workflow governs.',
    ],
    'telegram' => [
        'send' => 'Send messages to customers over Telegram.',
        'message' => [
            'delete' => 'Delete a sent Telegram message.',
        ],
        'bots' => [
            'view' => 'See the workspace\'s Telegram bots and their status.',
            'manage' => 'Add, configure and remove Telegram bots.',
        ],
        'connections' => [
            'manage' => 'Connect and disconnect Telegram Business accounts.',
        ],
        'health' => [
            'view' => 'See the Telegram channel\'s health status.',
        ],
    ],
    'webchat' => [
        'respond' => 'Reply to customers in the web chat widget.',
        'widgets' => [
            'view' => 'See the workspace\'s web chat widgets.',
            'configure' => 'Change web chat widget settings and appearance.',
        ],
    ],
    'sms' => [
        'send' => 'Send template-based SMS notifications.',
        'templates' => [
            'view' => 'See the SMS notification templates.',
            'create' => 'Create a new SMS template.',
            'edit' => 'Change an existing SMS template.',
            'delete' => 'Remove an SMS template.',
        ],
        'gateways' => [
            'view' => 'See the configured SMS gateways.',
            'manage' => 'Configure SMS gateway connections and credentials.',
        ],
        'optout' => [
            'manage' => 'View and manage customer SMS opt-outs — the opt-out itself is absolute.',
        ],
    ],
    'ai' => [
        'copilot' => [
            'use' => 'See AI copilot suggestions while working.',
            'insert_draft' => 'Insert a copilot suggestion into the reply box as a draft — it never sends itself.',
        ],
        'chatbot' => [
            'configure' => 'Change the AI chatbot\'s behaviour settings.',
        ],
        'guardrails' => [
            'edit' => 'Change the AI safety guardrails.',
        ],
        'thresholds' => [
            'edit' => 'Change AI confidence and handoff thresholds.',
        ],
        'decisions' => [
            'view' => 'See the AI\'s decision log.',
        ],
        'killswitch' => 'Stop all AI activity for the workspace immediately.',
    ],
    'kb' => [
        'view_internal' => 'Read internal-only knowledge base articles.',
        'articles' => [
            'create' => 'Write new knowledge base articles.',
            'edit' => 'Change existing knowledge base articles.',
            'delete' => 'Remove knowledge base articles.',
            'publish' => 'Publish or unpublish knowledge base articles.',
        ],
        'categories' => [
            'manage' => 'Organize knowledge base categories.',
        ],
        'feedback' => [
            'view' => 'See customer feedback on articles.',
        ],
    ],
    'automation' => [
        'rules' => [
            'view' => 'See the workspace\'s automation rules.',
            'edit' => 'Create and change automation rules.',
            'activate' => 'Turn automation rules on or off.',
        ],
    ],
    'macros' => [
        'personal' => [
            'manage' => 'Create and edit your own macros.',
        ],
        'shared' => [
            'manage' => 'Create and edit macros shared with the team.',
        ],
    ],
    'forms' => [
        'view' => 'See the workspace\'s forms.',
        'edit' => 'Create and change forms.',
        'publish' => 'Publish forms to customers.',
    ],
    'fields' => [
        'manage' => 'Define and change custom fields.',
    ],
    'sla' => [
        'policies' => [
            'view' => 'See the workspace\'s SLA policies.',
            'edit' => 'Change SLA policies and targets.',
        ],
    ],
    'org' => [
        'departments' => [
            'manage' => 'Create, change and archive departments.',
        ],
        'teams' => [
            'manage' => 'Create and change teams and their members.',
        ],
        'queues' => [
            'manage' => 'Create and change work queues.',
            'pull_in' => 'Pull additional work in from another queue.',
        ],
        'business_hours' => [
            'edit' => 'Change the business-hours schedule.',
        ],
    ],
    'admin' => [
        'users' => [
            'view' => 'See the workspace\'s staff users.',
            'manage' => 'Invite, edit and assign roles to users.',
            'disable' => 'Disable a user\'s access immediately.',
        ],
        'roles' => [
            'view' => 'See the workspace\'s roles and their permissions.',
            'manage' => 'Create, edit and delete roles and their grants.',
        ],
        'overrides' => [
            'manage' => 'Grant or deny individual permissions per user.',
        ],
        '2fa' => [
            'reset' => 'Clear a user\'s two-factor enrolment so they re-enrol at next login.',
        ],
    ],
    'reports' => [
        'view' => 'See reports within the role\'s data scope.',
        'dashboards' => [
            'create' => 'Build custom report dashboards.',
            'share' => 'Share dashboards with other staff.',
        ],
        'export' => 'Export report data out of the platform.',
        'schedule' => 'Schedule recurring report deliveries.',
        'supervisor' => [
            'live' => 'See the live supervisor dashboard.',
        ],
        'agents' => [
            'individual' => 'See per-person agent performance metrics.',
        ],
    ],
    'supervision' => [
        'monitor' => 'Watch an agent\'s live conversation.',
        'whisper' => 'Send an agent private guidance the customer never sees.',
        'reassign' => 'Reassign conversations between agents.',
        'agent_status' => [
            'force' => 'Change an agent\'s availability status on their behalf.',
        ],
        'broadcast' => 'Send an announcement to agents.',
    ],
    'settings' => [
        'view' => 'See the workspace settings.',
        'edit' => 'Change workspace settings.',
    ],
    'branding' => [
        'edit' => 'Change the workspace\'s branding and theme.',
    ],
    'features' => [
        'toggle' => 'Turn tenant feature flags on or off — flags hide, they never destroy data.',
    ],
    'languages' => [
        'manage' => 'Change the workspace\'s language settings.',
    ],
    'audit' => [
        'view' => 'Read the append-only audit log.',
        'export' => 'Export audit log entries out of the platform.',
    ],
    'apikeys' => [
        'machine' => [
            'manage' => 'Create and revoke machine API tokens.',
        ],
    ],
    'views' => [
        'share' => 'Share a saved inbox view with a team or department.',
        'manage' => 'Create, edit, duplicate and delete your own saved inbox views.',
    ],
];
