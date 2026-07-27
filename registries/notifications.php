<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Notification-category registry (CLAUDE.md §2, Doc 14)
|------------------------------------------------------------------------------
|
| Every notification the platform can send belongs to a category registered
| here, with its 3-layer opt model. Doc 14 fills this in.
|
| Entry schema (all fields required):
|   category_key    string  dot-namespaced, e.g. "notify.ticket.assigned"
|   label_i18n_key  string  i18n key for the human label
|   default_channels list<string>  non-empty subset of: "in_app" | "email" | "sms" | "telegram"
|   layer_model     string  the 3-layer opt model: "platform" | "tenant" | "user"
|                           (which layer may switch this category off)
|   owning_doc      string  "DocNN"
|
*/

return [
    // Intentionally empty in Session 0. First entries arrive with Doc 14
    // (categories referenced earlier are registered by their owning docs).
];
