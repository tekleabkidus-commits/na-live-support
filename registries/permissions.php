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
    // Intentionally empty in Session 0. First entries arrive with Doc 03.
];
