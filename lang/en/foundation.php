<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Foundation strings — English (Doc 01 FR-09, §14; C-08; invariant N-03)
|------------------------------------------------------------------------------
|
| Every user-visible string the foundation produces: state banners, provisioning
| step labels, error titles. Nothing user-visible is a literal anywhere in the
| codebase — the string-lint gate enforces that for templates, and these keys are
| where the text actually lives.
|
| Fallback chain (§14): user language → tenant default → English. English is the
| terminal fallback, so this file must be COMPLETE; lang/am may lag it during
| translation, and a missing Amharic key degrades to English rather than to a
| visible key name.
|
*/

return [
    'tenant' => [
        'banner' => [
            // FR-05: a non-dismissable banner on any permitted surface. Announced via
            // role="status" per §13, so the wording has to stand alone without the
            // visual context of the banner.
            'provisioning' => 'This workspace is being set up. Some features are not available yet.',
            'suspended' => 'This workspace is suspended. Incoming messages are still being received and saved, but no replies can be sent. Please contact your account manager.',
            'archived' => 'This workspace is archived and can no longer be changed.',
            'active' => '',
        ],
        'state_forbidden' => 'This workspace is :state, so this action is not available.',
    ],

    'error' => [
        // Titles for the RFC 9457 `code` enum (T-07). Short, human, and safe to
        // translate — clients branch on `code`, never on these.
        'quota_exceeded' => 'Usage limit reached',
        'tenant_state_forbidden' => 'Not available for this workspace',
        'unregistered_setting' => 'Unknown setting',
        'setting_write_conflict' => 'Someone else changed this first',
        'invalid_pagination' => 'Invalid page request',
        'validation_failed' => 'Some details need fixing',
        // Doc 02 SEC-06: refused because the request arrived over cleartext.
        'invalid_credentials' => 'Sign-in failed',
        'rate_limited' => 'Too many attempts',
        'insecure_transport' => 'Secure connection required',
        'insecure_transport_detail' => 'This service is only available over an encrypted connection. Reconnect using https and try again.',
    ],

    'provisioning' => [
        // §8: the provisioning progress screen shows per-step status.
        'step' => [
            'tenant_record' => 'Creating workspace',
            'default_settings' => 'Applying default settings',
            'default_roles' => 'Setting up roles',
            'sla_policy' => 'Applying service targets',
            'business_hours' => 'Setting business hours',
            'knowledge_base' => 'Preparing the knowledge base',
        ],
        'failed' => 'Setup could not finish at this step. Reference: :error_ref',
    ],

    'feature' => [
        // Registry #7 labels (FR-11) — what Doc 16's Backoffice shows next to each flag.
        'emergency_read_only' => 'Emergency read-only mode',
    ],
];
