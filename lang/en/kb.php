<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 10 — Knowledge Base strings (§14: manager UI under kb.*; C-08; N-03)
|------------------------------------------------------------------------------
|
| Opened at S10 step 1 with the tombstone states (FR-16) the resolver yields;
| every later surface adds its keys here and in lang/am/kb.php in the SAME
| commit. Destructive-tier strings (category delete-with-reassign, archive
| confirm, sanitizer override, visibility flip, restore, lock takeover) are
| marked for R-14 as they land.
|
*/

return [
    'tombstone' => [
        'current' => 'This article is current.',
        'retired' => 'This article was updated or removed.',
        'unknown' => 'This article is no longer available.',
        'read_current' => 'Read the current version',
    ],
    'notify' => [
        'review_requested' => 'Article submitted for review',
        'changes_requested' => 'Changes requested on your article',
        'lock_taken_over' => 'Someone took over editing your article',
    ],
];
