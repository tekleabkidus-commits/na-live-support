<?php

declare(strict_types=1);

// R-22's class-2 fill (S07 step 14) — the tag manager surface. C-08: every
// user-visible string is a key, en + am at launch.
return [
    'ui' => [
        'title' => 'Tags',
        'readonly_notice' => 'Tag management is read-only on small screens. Use a desktop browser to rename or merge tags.',
        'inventory_title' => 'Tags in use',
        'inventory_help' => 'Every tag currently carried by this workspace\'s tickets, most used first.',
        'inventory_empty' => 'No tags are in use yet.',
        'inventory_cap' => 'Showing the 200 most-used tags.',
        'ticket_count' => ':count tickets',
        'rename_action' => 'Rename…',
        'rewrite_title' => 'Rename a tag',
        'from_label' => 'Current tag',
        'to_label' => 'New name',
        'to_help' => 'Renaming to a tag that already exists merges the two.',
        'consequence' => 'Every historical ticket carrying ":from" will carry ":to" instead — :count tickets will be rewritten.',
        'merge_notice' => '":to" already exists on :count tickets. This is a merge — merged tags cannot be split apart afterwards.',
        'merge_phrase_label' => 'Type :to to confirm the merge',
        'rename_verb' => 'Rename tag',
        'merge_verb' => 'Merge tags',
        'cancel' => 'Cancel',
        'working' => 'Rewriting…',
        'result' => ':count tickets rewritten.',
        'result_audit' => 'Recorded in the audit log with the count.',
        'refused' => 'The rewrite was refused.',
    ],
];
