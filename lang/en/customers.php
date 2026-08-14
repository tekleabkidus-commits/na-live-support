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
];
