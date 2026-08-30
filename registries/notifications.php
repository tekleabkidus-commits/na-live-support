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
    // ── Doc 08 (Customers) — registered at S08 step 1a, before their producers
    //    (the S23 C3 discipline: no producer ships uncategorised).
    [
        // NFR-02: merge is async UX — the completion notification is the
        // contract's other half (progress rides user.{id} realtime; completion
        // is durable and opt-modelled here).
        'category_key' => 'notify.customers.merge_completed',
        'label_i18n_key' => 'customers.notify.merge_completed',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc08',
    ],
    [
        // SEC-04: the export archive builds in the background; the signed-URL
        // ready signal arrives here ("you'll get a link when it is ready").
        'category_key' => 'notify.customers.export_ready',
        'label_i18n_key' => 'customers.notify.export_ready',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc08',
    ],

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

    // ── Doc 07 (Unified Inbox) — FR-26's enumerated bell set, registered at
    //    S07 step 1 BEFORE any producer ships (the C3 precedent). All
    //    layer_model `user` and in_app-only by default: BR-05 — sounds and
    //    desktop notifications are per-user choices; the platform never
    //    force-enables noise. Per-category preference SURFACES are Doc 16's;
    //    the bell's quick mute is Doc 07's.
    [
        // FR-26: a ticket was assigned to me.
        'category_key' => 'notify.inbox.assignment',
        'label_i18n_key' => 'inbox.notify.assignment',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // FR-26: I was @mentioned in an internal note (Doc 04 note.mentioned).
        'category_key' => 'notify.inbox.mention',
        'label_i18n_key' => 'inbox.notify.mention',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // FR-26: activity on a ticket I follow (Doc 06 FR-18's follower events).
        'category_key' => 'notify.inbox.follower',
        'label_i18n_key' => 'inbox.notify.follower',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // FR-26: a ticket was escalated to me.
        'category_key' => 'notify.inbox.escalation',
        'label_i18n_key' => 'inbox.notify.escalation',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // FR-26: SLA warning on one of my tickets. The PRODUCER is Doc 12's
        // (warned/breached evaluation); the category closes the bell's type
        // set now, so the row type renders against fixtures until then.
        'category_key' => 'notify.inbox.sla_warning',
        'label_i18n_key' => 'inbox.notify.sla_warning',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // FR-26: reply window closing on one of my tickets (consumes
        // telegram.window.closing; Doc 05 FR-22's notification option —
        // OPT-IN, hence in_app default with the user layer).
        'category_key' => 'notify.inbox.window_closing',
        'label_i18n_key' => 'inbox.notify.window_closing',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // FR-26: a bulk operation I requested completed (ticket.bulk_completed).
        'category_key' => 'notify.inbox.bulk_completed',
        'label_i18n_key' => 'inbox.notify.bulk_completed',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 09 FR-18/SEC-05: kill-switch state changes are HIGH-VISIBILITY —
        // every admin sees the banner and the notification (the in-app
        // mirror of the audit entry).
        'category_key' => 'notify.ai.killswitch_changed',
        'label_i18n_key' => 'ai.notify.killswitch_changed',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 NFR-06/EC-12: the 80% daily-token alert (Doc 01's
        // quota.warning_80 metric surfaced as a notification to admins).
        'category_key' => 'notify.ai.quota_80',
        'label_i18n_key' => 'ai.notify.quota_80',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 FR-17 (S09 step 11): anomaly alerts ("withdrawal complaints
        // +240% since 14:00") + sentiment-watch feed items to supervisors.
        'category_key' => 'notify.ai.anomaly',
        'label_i18n_key' => 'ai.notify.anomaly',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 FR-17 (S09 step 11): the daily digest's delivery (per
        // manager, localized).
        'category_key' => 'notify.ai.digest',
        'label_i18n_key' => 'ai.notify.digest',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 10 FR-07: a variant was submitted for review (reviewers) (S10 step 4; registered BEFORE its producer ships — the S23 C3 discipline; bell delivery = a Doc 07 projector packet at the checkpoint).
        'category_key' => 'notify.kb.review_requested',
        'label_i18n_key' => 'kb.notify.review_requested',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07: a reviewer requested changes (the author) (S10 step 4; registered BEFORE its producer ships — the S23 C3 discipline; bell delivery = a Doc 07 projector packet at the checkpoint).
        'category_key' => 'notify.kb.changes_requested',
        'label_i18n_key' => 'kb.notify.changes_requested',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-05/T-07: your editing lock was taken over (the displaced holder) (S10 step 4; registered BEFORE its producer ships — the S23 C3 discipline; bell delivery = a Doc 07 projector packet at the checkpoint).
        'category_key' => 'notify.kb.lock_taken_over',
        'label_i18n_key' => 'kb.notify.lock_taken_over',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 11 FR-15: a supervisor's broadcast note to queue members (S11; registered BEFORE its producer ships — the S23 C3 discipline; DELIVERY is Doc 16's [D16]).
        'category_key' => 'notify.routing.broadcast',
        'label_i18n_key' => 'routing.notify.broadcast',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc11',
    ],
    [
        // Doc 11 FR-11 / EC-04: a wait-fallback skill queue has held past routing.skill.no_match_alert_minutes (supervisors) (S11; delivery [D16]).
        'category_key' => 'notify.routing.no_skill_match',
        'label_i18n_key' => 'routing.notify.no_skill_match',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc11',
    ],
    [
        // Doc 11 FR-16: a ticket entered the Rescue view (heartbeat loss / unreachable / orphan) (S11; delivery [D16]).
        'category_key' => 'notify.routing.rescue_flagged',
        'label_i18n_key' => 'routing.notify.rescue_flagged',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc11',
    ],
    [
        // Doc 11 BR-07: the same pull-in recurred routing.pull_in.recurrence_hint times in a week — the "make permanent?" nudge, in-app only, never an auto-conversion (S11).
        'category_key' => 'notify.routing.pull_in_recurring',
        'label_i18n_key' => 'routing.notify.pull_in_recurring',
        'default_channels' => ['in_app'],
        'layer_model' => 'user',
        'owning_doc' => 'Doc11',
    ],
];
