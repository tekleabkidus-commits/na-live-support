<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| string-lint allowlist — genuine exceptions ONLY, each with a reason.
|------------------------------------------------------------------------------
|
| Matching is exact per (file, trimmed string): adding any NEW literal to an
| allowlisted file still fails the gate. Remove entries the moment the
| underlying reason disappears.
|
*/

return [
    // Session 0 placeholder page. The i18n layer itself is Doc 01 FR-09
    // (Session 1); this page is deleted when Doc 07's real agent app lands.
    // Every entry below must disappear with it.
    'resources/js/Pages/Welcome.vue' => [
        'Na Live Support',
        'Session 0 bootstrap',
        // The boot-status pill text comes from a Vue interpolation, which the
        // linter correctly does not treat as a literal text node.
    ],
];
