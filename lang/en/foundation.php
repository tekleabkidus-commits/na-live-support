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
        'identity_already_linked' => 'Identity already linked',
        'media_not_servable' => 'Media not servable',
        'kb_lock_held' => 'Someone else is editing this article',
        'kb_save_conflict' => 'The article changed since your last save',
        'kb_category_has_articles' => 'The category still holds articles',
        'routing_refused' => 'The routing operation was refused',
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
        // Doc 02 FR-20: the bearer surface's closed code set (step-9 ruling).
        'invalid_token' => 'Invalid API token',
        'token_expired' => 'API token expired',
        'insufficient_scope' => 'Missing permission',
        'forbidden' => 'Not permitted',
        // Doc 02 FR-21/AC-06: widget session refused — origin or ancestor off-list.
        'origin_not_allowed' => 'This site can\'t use this chat widget',
        // Doc 02 FR-23/FR-24/AC-07: webhook second-credential refusal.
        'webhook_rejected' => 'Webhook request rejected',
        // Doc 04 FS-05: re-drive attempted on an item that is no longer parked.
        'dead_letter_not_parked' => 'Already re-driven',
        // Doc 07 FR-06/AC-02: the saved-view surface (S07 step 4).
        'view_filters_invalid' => 'View filters not recognised',
        'read_only_view' => 'Shared views are read-only',
        'view_not_found' => 'View not found',
        'resource_not_found' => 'Not found',
        'reopen_confirmation_required' => 'The ticket is resolved — confirm to reopen and send',
        'transition_rejected' => 'The status change was refused',
        'conversation_blocked' => 'This conversation cannot receive replies',
        'already_claimed' => 'Someone claimed this ticket first',
    ],

    'provisioning' => [
        // §8: the provisioning progress screen shows per-step status.
        'step' => [
            'tenant_record' => 'Creating workspace',
            'default_settings' => 'Applying default settings',
            'default_roles' => 'Setting up roles',
            'organization' => 'Setting up departments and queues',
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
