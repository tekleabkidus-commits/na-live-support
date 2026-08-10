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
    // ── Doc 05 (Telegram) — the registry's first entries, per S23 correction
    //    C3: every admin notification Doc 05 mints is a registered category,
    //    same-commit as its producer (producers land steps 4–11; categories
    //    registered at step 1 so no producer can ship uncategorised).
    [
        // FR-09/US-04: connection paused or revoked — the operational alert.
        'category_key' => 'notify.telegram.connection_state',
        'label_i18n_key' => 'telegram.notify.connection_state',
        'default_channels' => ['in_app', 'email'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-05 direct mode: business updates arriving on a direct-mode bot —
        // the misconfiguration signal.
        'category_key' => 'notify.telegram.misconfigured_source',
        'label_i18n_key' => 'telegram.notify.misconfigured_source',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc05',
    ],
    [
        // SEC-04: a connection pending unconfirmed for 10 minutes — a
        // security control's alert, not a courtesy.
        'category_key' => 'notify.telegram.connection_pending',
        'label_i18n_key' => 'telegram.notify.connection_pending',
        'default_channels' => ['in_app', 'email'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc05',
    ],
    [
        // FS-04: flap damping — >3 transitions/hour collapse into one digest.
        'category_key' => 'notify.telegram.connection_flap_digest',
        'label_i18n_key' => 'telegram.notify.connection_flap_digest',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-22: the optional closing-window agent notification (gated by
        // telegram.window.closing_notify).
        'category_key' => 'notify.telegram.window_closing',
        'label_i18n_key' => 'telegram.notify.window_closing',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc05',
    ],
    [
        // FS-02: the silently-broken-webhook alert (pending growth + no
        // inbound past threshold on a normally-active bot) — producer is
        // TelegramHealthPoll (S23 step 11, C3 same-commit).
        'category_key' => 'notify.telegram.webhook_silent',
        'label_i18n_key' => 'telegram.notify.webhook_silent',
        'default_channels' => ['in_app', 'email'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc05',
    ],
];
