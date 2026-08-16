<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 08 — Customer Profiles & Identity (C-08: every user-visible string a key)
|------------------------------------------------------------------------------
|
| Opened at S08 step 1a with the two notification-category labels (registered
| the same commit as their registry rows). The full customers.* surface strings
| land with their owning steps; machine-authored Amharic joins the R-14
| native-review queue at close-out, destructive tier flagged.
|
*/

return [
    'block' => [
        'cascade_reason' => 'Customer-level block cascade: :reason',
        'cascade_restore_reason' => 'Customer-level unblock — restoring the cascade-blocked conversations.',
    ],
    'merge' => [
        'linked_work_note' => 'Flagged as linked work by a customer merge — both profiles held an open ticket (sanctioned multi-open).',
    ],
    'identity' => [
        'duplicate' => 'That :type already belongs to another profile — a value can only be on one customer (merge instead?).',
    ],
    'notify' => [
        'merge_completed' => 'Customer merge completed',
        'export_ready' => 'Customer export ready',
    ],
    'panel' => [
        'not_found' => 'Customer not found.',
        'empty' => 'Open a conversation to see its customer here.',
        'loading' => 'Loading customer…',
        'error' => 'This section failed to load.',
        'retry' => 'Retry',
        'identities' => 'Identities',
        'betting' => 'Betting details',
        'history' => 'History',
        'recent_tickets' => 'Recent tickets',
        'notes' => 'Notes',
        'files' => 'Files',
        'tickets_line' => ':count tickets · :open open',
        'rating_line' => 'of 5 · :count ratings',
        'last_contact' => 'since last contact',
        'no_name' => 'Customer #:short — No name yet',
        'blocked_banner' => 'This customer is blocked. :reason',
        'rg_banner' => 'Responsible-gambling flag is set.',
        'unreachable' => 'unreachable',
        'from_channel' => 'from :channel',
        'by_staff' => 'added by staff',
    ],
    'files' => [
        'not_found' => 'File not found.',
        'not_servable' => 'This file cannot be downloaded — it did not pass the malware scan.',
    ],
    'export' => [
        'not_ready' => 'This export is not ready — no complete archive exists yet.',
        'watermark' => 'Customer data export — requested by :requester at :at. Handle per data-protection policy.',
    ],
    'notes' => [
        'onboarding_title' => 'Notes stay on the profile',
        'onboarding_body' => 'Customer notes are internal. They never reach the customer and never move to a conversation.',
    ],
    'fields' => [
        'betting_username' => 'Betting username',
        'player_id' => 'Player ID',
        'brand' => 'Brand',
        'loyalty_tier' => 'Loyalty tier',
        'staff_collected' => 'Staff-collected',
        'retired_refusal' => 'The field :field is retired — its value is read-only.',
        'retired' => 'retired field',
        'duplicate_hint' => 'also on :count other profiles',
    ],
];
