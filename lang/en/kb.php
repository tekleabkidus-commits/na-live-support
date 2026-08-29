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
    'blocks' => [
        'also_asked' => 'Also asked', // FR-06 FAQ variants — the appended block's label (step 9)
    ],
    'notify' => [
        'review_requested' => 'Article submitted for review',
        'changes_requested' => 'Changes requested on your article',
        'lock_taken_over' => 'Someone took over editing your article',
    ],
    'flow' => [ // FR-19/BR-04 (step 11): the bot-menu contract's labels — rendering is Doc 05/12's
        'category_title' => 'Help topics: :name',
        'category_empty' => 'No articles here yet.',
        'category_unknown' => 'This topic is no longer available.',
        'article_summary' => ':summary — read more: :link',
        'start_chat' => 'Chat with us',
    ],
];
