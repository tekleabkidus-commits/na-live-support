<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 07 — Unified Inbox strings (§14: everything under inbox.*, en + am)
|------------------------------------------------------------------------------
| Skeleton laid at S07 step 1 with the keys the registries already name;
| every later step adds its surface's keys HERE and in lang/am/inbox.php in
| the same commit (string-lint enforces; en/am parity asserted). All Amharic
| is machine-authored and joins the standing R-14 native-review queue.
*/

return [
    'notify' => [
        'assignment' => 'Ticket assigned to me',
        'mention' => 'Mentioned in an internal note',
        'follower' => 'Activity on a followed ticket',
        'escalation' => 'Ticket escalated to me',
        'sla_warning' => 'SLA warning on my ticket',
        'window_closing' => 'Reply window closing on my ticket',
        'bulk_completed' => 'Bulk operation completed',
    ],
    'feature' => [
        'voice_recording' => 'Composer voice recording',
    ],
    'selection' => [
        'empty' => 'Nothing to select — this view is empty.',
        'consumed' => 'This selection was already executed — reselect to run another action.',
    ],
];
