<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Permission labels — English (Doc 03 FR-01, §14; C-08; N-03)
|------------------------------------------------------------------------------
|
| One entry per catalog key in registries/permissions.php; CatalogSnapshotTest
| asserts completeness in BOTH locales. Amharic is machine-authored and queued
| for native review (standing queue; ruling R-14 flags the destructive tier).
|
*/

return [
    'tickets' => [
        'view' => 'View tickets',
        'create' => 'Create tickets',
        'edit' => 'Edit tickets',
        'reply' => 'Reply to tickets',
        'note' => 'Add internal notes',
        'assign' => 'Assign tickets',
        'assign_self' => 'Self-assign tickets',
        'status' => [
            'change' => 'Change ticket status',
        ],
        'priority' => [
            'change' => 'Change ticket priority',
        ],
        'department' => [
            'change' => 'Move ticket between departments',
        ],
        'close' => 'Close tickets',
        'reopen' => 'Reopen tickets',
        'merge' => 'Merge tickets',
        'split' => 'Split tickets',
        'escalate' => 'Escalate tickets',
        'follow' => 'Follow tickets',
        'tags' => [
            'manage' => 'Manage ticket tags',
        ],
        'fields' => [
            'edit' => 'Edit ticket fields',
        ],
        'spam' => [
            'mark' => 'Mark as spam',
        ],
        'delete' => 'Delete tickets',
        'export' => 'Export tickets',
    ],
    'conversations' => [
        'view' => 'View conversations',
        'history' => [
            'full' => 'View full conversation history',
        ],
        'transcript' => [
            'export' => 'Export transcripts',
        ],
    ],
    'customers' => [
        'view' => 'View customers',
        'create' => 'Create customers',
        'edit' => 'Edit profile',
        'notes' => [
            'add' => 'Add notes',
            'view' => 'View notes',
        ],
        'identity' => [
            'link' => 'Link identities',
            'unlink' => 'Unlink identities',
        ],
        'merge' => 'Merge profiles',
        'block' => 'Block customers',
        'rg_flag' => [
            'view' => 'See RG flags',
            'set' => 'Set RG flags',
        ],
        'export' => 'Export customers',
        'delete' => 'Delete customers',
    ],
    'telegram' => [
        'send' => 'Send on Telegram',
        'message' => [
            'delete' => 'Delete a message',
        ],
        'bots' => [
            'view' => 'See the bots',
            'manage' => 'Manage bots',
        ],
        'connections' => [
            'manage' => 'Manage connections',
        ],
        'health' => [
            'view' => 'See channel health',
        ],
    ],
    'webchat' => [
        'respond' => 'Respond in web chat',
        'widgets' => [
            'view' => 'View chat widgets',
            'configure' => 'Configure chat widgets',
        ],
    ],
    'sms' => [
        'send' => 'Send SMS notifications',
        'templates' => [
            'view' => 'View SMS templates',
            'create' => 'Create SMS templates',
            'edit' => 'Edit SMS templates',
            'delete' => 'Delete SMS templates',
        ],
        'gateways' => [
            'view' => 'View SMS gateways',
            'manage' => 'Manage SMS gateways',
        ],
        'optout' => [
            'manage' => 'Manage SMS opt-outs',
        ],
    ],
    'ai' => [
        'copilot' => [
            'use' => 'Use AI copilot',
            'insert_draft' => 'Insert copilot drafts',
        ],
        'chatbot' => [
            'configure' => 'Configure AI chatbot',
        ],
        'guardrails' => [
            'edit' => 'Edit AI guardrails',
        ],
        'thresholds' => [
            'edit' => 'Edit AI thresholds',
        ],
        'decisions' => [
            'view' => 'View AI decisions',
        ],
        'killswitch' => 'Use AI kill switch',
    ],
    'kb' => [
        'view_internal' => 'View internal articles',
        'articles' => [
            'create' => 'Create articles',
            'edit' => 'Edit articles',
            'delete' => 'Delete articles',
            'publish' => 'Publish articles',
        ],
        'categories' => [
            'manage' => 'Manage categories',
        ],
        'feedback' => [
            'view' => 'View article feedback',
        ],
    ],
    'automation' => [
        'rules' => [
            'view' => 'View automation rules',
            'edit' => 'Edit automation rules',
            'activate' => 'Activate automation rules',
        ],
    ],
    'macros' => [
        'personal' => [
            'manage' => 'Manage personal macros',
        ],
        'shared' => [
            'manage' => 'Manage shared macros',
        ],
    ],
    'forms' => [
        'view' => 'View forms',
        'edit' => 'Edit forms',
        'publish' => 'Publish forms',
    ],
    'fields' => [
        'manage' => 'Manage custom fields',
    ],
    'sla' => [
        'policies' => [
            'view' => 'View SLA policies',
            'edit' => 'Edit SLA policies',
        ],
    ],
    'org' => [
        'departments' => [
            'manage' => 'Manage departments',
        ],
        'teams' => [
            'manage' => 'Manage teams',
        ],
        'queues' => [
            'manage' => 'Manage queues',
            'pull_in' => 'Pull in from queues',
        ],
        'business_hours' => [
            'edit' => 'Edit business hours',
        ],
    ],
    'admin' => [
        'users' => [
            'view' => 'View users',
            'manage' => 'Manage users',
            'disable' => 'Disable users',
        ],
        'roles' => [
            'view' => 'View roles',
            'manage' => 'Manage roles',
        ],
        'overrides' => [
            'manage' => 'Manage user overrides',
        ],
        '2fa' => [
            'reset' => 'Reset user 2FA',
        ],
    ],
    'reports' => [
        'view' => 'View reports',
        'dashboards' => [
            'create' => 'Create dashboards',
            'share' => 'Share dashboards',
        ],
        'export' => 'Export reports',
        'schedule' => 'Schedule reports',
        'supervisor' => [
            'live' => 'View live supervisor board',
        ],
        'agents' => [
            'individual' => 'View individual agent metrics',
        ],
    ],
    'supervision' => [
        'monitor' => 'Monitor conversations',
        'whisper' => 'Whisper to agents',
        'reassign' => 'Reassign work',
        'agent_status' => [
            'force' => 'Force agent status',
        ],
        'broadcast' => 'Broadcast to agents',
    ],
    'settings' => [
        'view' => 'View settings',
        'edit' => 'Edit settings',
    ],
    'branding' => [
        'edit' => 'Edit branding',
    ],
    'features' => [
        'toggle' => 'Toggle features',
    ],
    'languages' => [
        'manage' => 'Manage languages',
    ],
    'audit' => [
        'view' => 'View audit log',
        'export' => 'Export audit log',
    ],
    'apikeys' => [
        'machine' => [
            'manage' => 'Manage machine API keys',
        ],
    ],
    'views' => [
        'share' => 'Share saved views',
        'manage' => 'Create and edit saved views',
    ],
];
