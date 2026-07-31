<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Permission registry (CLAUDE.md §2 — ~118 keys once Doc 03 lands)
|------------------------------------------------------------------------------
|
| Every route/action must map to a key registered here; an unmapped route
| fails the `permission-mapping` CI gate. Doc 03 (Session 3) fills this in.
|
| Entry schema (all fields required):
|   key             string  dot-namespaced, e.g. "tickets.assign"
|   label_i18n_key  string  i18n key for the human label, e.g. "perm.tickets.assign"
|   group           string  UI grouping, e.g. "tickets", "conversations"
|   scopes          list<string>  non-empty subset of: "Own" | "Team" | "Dept" | "All"
|   owning_doc      string  "DocNN", the spec document that defines this key
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | First entries (Doc 02 session)
    |--------------------------------------------------------------------------
    |
    | Registering the FIRST key flips the permission-mapping gate from warn to
    | blocking for EVERY route — that flip is deliberate and happened in the
    | Doc 02 skeleton commit, with all pre-existing routes mapped or exempted in
    | the same commit.
    |
    | Key NAMES are Doc 03's: its §4 catalog is the naming authority
    | (settings.view, settings.edit, admin.users.*, admin.2fa.reset,
    | apikeys.machine.manage, …) and its NFR-03 forbids ever renaming a key. So
    | entries registered before Doc 03's session carry owning_doc "Doc03" — the
    | document that DEFINES the key — even though Doc 02's routes are the first
    | consumers. Doc 02 invents no key names of its own; scopes here are the
    | narrowest reading and Doc 03's session is the authority that widens them.
    |
    */

    [
        'key' => 'settings.view',
        'label_i18n_key' => 'perm.settings.view',
        'group' => 'settings',
        'scopes' => ['All'],
        'owning_doc' => 'Doc03',
    ],
    [
        'key' => 'settings.edit',
        'label_i18n_key' => 'perm.settings.edit',
        'group' => 'settings',
        'scopes' => ['All'],
        'owning_doc' => 'Doc03',
    ],
];
