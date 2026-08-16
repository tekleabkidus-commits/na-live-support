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

    // B6-S06 (Doc 05 step 11): the frozen frames draw the PERMISSION KEY as
    // a mono machine-identifier chip ("Permission gating shown", checklist
    // §Q) — it is a registry key, not copy, and must render verbatim in
    // every locale. The §K key-cap labels (Space/Esc/Enter/Home/End) are
    // PHYSICAL KEY NAMES drawn as keycaps — a translated keycap would lie
    // about the keyboard.
    'resources/js/Pages/Admin/Telegram/Health.vue' => [
        'telegram.health.view',
        'Enter',
        'Home / End',
    ],
    'resources/js/Pages/Admin/Telegram/MenuDesigner.vue' => [
        'Space',
        'Esc',
    ],

    // S07 step 12: FR-07's own words cap count DISPLAY at "999+" — a
    // numeral-plus literal identical in every locale (locale digits apply
    // to the count itself, not the cap marker); and the palette's Esc
    // keycap is a PHYSICAL KEY NAME per §14's layout-aware physical keys —
    // a translated keycap would lie about the keyboard (the MenuDesigner
    // precedent above).
    'resources/js/Components/Inbox/NotificationBell.vue' => [
        "999 ? '999+' : unread }}",
    ],
    'resources/js/Components/Inbox/CommandPalette.vue' => [
        'Esc',
    ],

    // S08 step 11: the typed confirm TOKENS are drawn LATIN in both layouts
    // and both languages (B4-S04's own law; the plan's R-14 note: "the token
    // itself stays Latin") — a translated token would break the typed-match
    // and lie about what to type. The words are rendered beside their inputs
    // as the thing to copy, not as prose.
    'resources/js/Pages/Customers/Profile.vue' => [
        'UNLINK',
        'MERGE',
    ],
];
