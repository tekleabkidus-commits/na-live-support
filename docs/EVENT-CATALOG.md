# Event catalog

> **GENERATED — do not edit.** Source of truth is
> `app/Support/Events/EventCatalog.php`. Regenerate with
> `php ci/event-catalog.php --write`; the `event-catalog` CI gate fails on drift.

This is the integration contract between the module that publishes an event and every
module that consumes it (Doc 01 §21). A producer changing a payload can see here who
will notice; a consumer can see what it is entitled to receive.

**Payload keys are exhaustive and enforced.** `EventPublisher` rejects a payload with a
missing key AND one with an extra key — the second is how PII leaks into an event stream
that is retained for 13 months (SEC-07, NFR-07).

98 event(s).

## `agent.status_changed`

FR-28: an agent's availability changed (online|away|offline; reason manual|auto_idle|heartbeat_lost|forced|logout). Doc 11 consumes it for routability; Doc 15 counts transitions — active-time metrics derive from activity events, never from this state (BR-06).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `status`, `reason`, `set_by_user_id`
- **Producers:** Doc07/AgentStatus
- **Consumers:** Doc11/Routing, Doc15/Reporting

## `ai.config.published`

FR-04/FR-21: an AI config version (persona, thresholds, guardrails, provider routing, prompt template, RG classifier) was published — instant, zero-deploy, with rollback as republish. SEC-05 high-visibility; the audit entry carries actor + old/new.

- **Schema version:** 1
- **Payload:** `tenant_id`, `kind`, `key`, `version`, `config_version_id`
- **Producers:** Doc09/ConfigVersions
- **Consumers:** Doc15/Reporting, Doc17/Audit

## `ai.decision.logged`

FR-20/FR-21: an AI task invocation was logged (SAMPLED to the bus — the table is the record; the stream is a signal).

- **Schema version:** 1
- **Payload:** `tenant_id`, `decision_id`, `surface`, `task`, `action`, `error_category`
- **Producers:** Doc09/DecisionWriter
- **Consumers:** Doc15/Reporting

## `auth.2fa.challenge_failed`

A 2FA challenge attempt failed; the fifth voids the login attempt (FR-07).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `attempt`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.2fa.disabled`

A user turned their own 2FA off, proving a current code first (§4.8).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.2fa.enabled`

A user confirmed 2FA enrolment with a valid code (FR-06).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.2fa.reset_by_admin`

An admin cleared a user's 2FA; the user re-enrols at next login (FR-08).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `admin_id`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.ip.blocked`

A correct credential was refused because the role's IP allowlist excludes the address (SEC-05; user-facing error stays generic per FR-02).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `ip_hash`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.login.failed`

A staff authentication attempt failed, with FR-04's reason category.

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `reason`, `ip_hash`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.login.succeeded`

A staff user completed authentication and a session was established (FR-04).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `device_id`, `new_device`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.logout`

A staff user ended their session deliberately (§4.8).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit

## `auth.password.changed`

A password was set outside the reset flow: authenticated self-change (FR-14) or an invitation's first password (FR-17); `origin` says which.

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `origin`
- **Producers:** Doc02/PasswordLifecycle
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.password.reset_completed`

A reset token was redeemed: new password set, all sessions revoked, this account unlocked (FR-16, EC-11, R-6).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `origin`
- **Producers:** Doc02/PasswordLifecycle
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.password.reset_requested`

A reset was requested and a token minted for THIS account; one event per matched account (FR-16).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/PasswordLifecycle
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.token.created`

An API token was minted — personal, machine, or a rotation's replacement (FR-18/FR-19); `user_id` is the owner (a service account for machine tokens, BR-03).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `token_id`
- **Producers:** Doc02/TokenMinter
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.token.revoked`

An API token was revoked — self-service, admin action, or BR-04's disable cascade; the audit entry carries which.

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `token_id`
- **Producers:** Doc02/TokenRevoker
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.token.scope_denied`

A token-authenticated request failed the T-05 intersection for the named permission (FR-20 insufficient_scope, AC-05).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `token_id`, `permission`
- **Producers:** Doc02/RequirePermission
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `channel.health.changed`

FS-01's health signal, fired on TRANSITION only (A-22 is the canonical home of transition-only): a channel endpoint crossed a health threshold or recovered. Status vocabulary healthy|degraded|down (v2, ruling A-22). Event name filed as interpretation A-4.

- **Schema version:** 2
- **Payload:** `tenant_id`, `channel`, `channel_ref_id`, `status`
- **Producers:** Doc04/ChannelHealthTracker, Doc05/HealthRollup
- **Consumers:** Doc05/ChannelSurfaces, Doc07/Inbox, Doc13/ChannelSurfaces, Doc18/Alerting

## `conversation.blocked`

A conversation was blocked (FR-03): outbound refuses (BR-02), inbound quarantines (FR-11). The reason lives in the audit entry.

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`
- **Producers:** Doc04/ConversationBlocking
- **Consumers:** Doc06/SpamDisposition, Doc07/Inbox

## `conversation.created`

First contact created THE permanent conversation for {tenant, channel endpoint, external chat} (Doc 04 FR-01).

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `customer_id`, `channel`
- **Producers:** Doc04/InboundPersister
- **Consumers:** Doc06/TicketRouting, Doc07/Inbox

## `conversation.lane_skipped`

FR-15's 10-minute head-block cap fired: the stuck head was demoted and the lane drains past it (event name filed as interpretation A-4).

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `message_id`
- **Producers:** Doc04/LaneWorker
- **Consumers:** Doc07/Inbox, Doc18/Alerting

## `conversation.unblocked`

A blocked conversation returned to active. Nothing retro-fans-out (FR-11): quarantined rows stay off the bus, by construction.

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`
- **Producers:** Doc04/ConversationBlocking
- **Consumers:** Doc06/SpamDisposition, Doc07/Inbox

## `customer.blocked`

FR-22/FR-18 (T-06): customer-level block — the cascade consumer quiets every conversation, and the outbound guard refuses new sends independently (belt and braces, FS-04).

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `blocked_by`
- **Producers:** Doc08/BlockCustomer
- **Consumers:** Doc08/BlockingCascade, Doc07/ListProjector, Doc17/Audit

## `customer.created`

FR-22/FR-02: a customer came into existence — a resolver shell (created_source=telegram|webchat) or a staff/api creation. Shells are full customers (FR-02).

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `created_source`
- **Producers:** Doc08/CustomerResolver, Doc08/Customers
- **Consumers:** Doc08/SearchIndexer, Doc15/Reporting, Doc17/Audit

## `customer.fields_changed`

FR-22/FR-16: custom-field values changed for entity=customer (Doc 12 definitions; the player_id change re-feeds the duplicate-hint counts and the search index).

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `field_keys`
- **Producers:** Doc08/CustomerProfile
- **Consumers:** Doc08/SearchIndexer, Doc17/Audit

## `customer.identity.linked`

FR-22/FR-09 (and FR-07's shell creation): an identity attached to a customer. type=telegram|web|phone|email; source=channel_auto|staff|api (FR-05).

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `identity_id`, `type`, `source`
- **Producers:** Doc08/LinkIdentity, Doc08/CustomerResolver
- **Consumers:** Doc08/SearchIndexer, Doc17/Audit

## `customer.identity.unlinked`

FR-22/FR-10: an identity moved off a profile onto a fresh shell, carrying its conversations/tickets. High-risk, typed-confirmed, heavily audited.

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `identity_id`, `type`, `new_customer_id`
- **Producers:** Doc08/UnlinkIdentity
- **Consumers:** Doc08/SearchIndexer, Doc07/ListProjector, Doc17/Audit

## `customer.merged`

FR-22/FR-12 (T-03): a merge completed — merged became a tombstone, everything re-pointed to the survivor. Doc 07's projector rewrites list rows from it (EC-01's live re-point).

- **Schema version:** 1
- **Payload:** `tenant_id`, `survivor_id`, `merged_id`, `snapshot_id`
- **Producers:** Doc08/CustomerMerge
- **Consumers:** Doc07/ListProjector, Doc08/SearchIndexer, Doc08/Rollups, Doc15/Reporting, Doc17/Audit

## `customer.note.added`

FR-22/FR-17: a customer note was added (body never rides the stream — N-04's cousin discipline; the pinned one surfaces in the Doc 07 panel header).

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `note_id`, `author_id`, `pinned`
- **Producers:** Doc08/CustomerNotes
- **Consumers:** Doc07/ListProjector, Doc17/Audit

## `customer.rg_flag.cleared`

FR-22/FR-15: the flag was cleared (permission + mandatory reason; history retained forever). Doc 09's calibration consumes clear-reasons (EC-06) — via the history row, not this payload.

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `history_id`
- **Producers:** Doc08/RgFlagService
- **Consumers:** Doc11/Routing, Doc12/Automation, Doc14/SmsSuppression, Doc09/Calibration, Doc17/Audit

## `customer.rg_flag.set`

FR-22/FR-14: the RG flag was set (source=staff|ai). FR-15's contracts consume it: Doc 11 routes new tickets to the RG queue, Doc 12 suppresses promotional automations, Doc 14 suppresses non-essential SMS.

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `history_id`, `source`
- **Producers:** Doc08/RgFlagService
- **Consumers:** Doc11/Routing, Doc12/Automation, Doc14/SmsSuppression, Doc17/Audit

## `customer.unblocked`

FR-22/FR-18: the distinct unblock action (rides customers.block per A-39) — conversation states restore; quarantined messages are not replayed.

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `unblocked_by`
- **Producers:** Doc08/BlockCustomer
- **Consumers:** Doc08/BlockingCascade, Doc07/ListProjector, Doc17/Audit

## `customer.unmerged`

FR-22/FR-13: a 30-day undo executed — pre-merge pointers restored byte-identically (AC-03); post-merge objects stayed with the survivor.

- **Schema version:** 1
- **Payload:** `tenant_id`, `survivor_id`, `restored_id`, `snapshot_id`
- **Producers:** Doc08/UnmergeCustomer
- **Consumers:** Doc07/ListProjector, Doc08/SearchIndexer, Doc08/Rollups, Doc17/Audit

## `customer.updated`

FR-22/FR-01: profile attributes changed (display_name, language, country, tags…). The name list rides the payload; values live in audit.

- **Schema version:** 1
- **Payload:** `tenant_id`, `customer_id`, `changed`
- **Producers:** Doc08/CustomerProfile
- **Consumers:** Doc08/SearchIndexer, Doc07/ListProjector, Doc17/Audit

## `media.infected`

The scan found malware: item blocked with NO download path for any role (SEC-04); bytes never stored.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `index`, `signature`
- **Producers:** Doc04/MediaPipeline
- **Consumers:** Doc07/Realtime, Doc18/SecurityMonitoring

## `media.needs_ocr`

T-05 hook, emitted after media.stored for photos: Doc 09 runs OCR and writes back ocr_ref; core stays provider-agnostic.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `index`, `storage_key`
- **Producers:** Doc04/MediaPipeline
- **Consumers:** Doc09/Ocr

## `media.needs_transcript`

T-05 hook, emitted after media.stored for voice: Doc 09 transcribes and writes back transcript_ref; core stays provider-agnostic.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `index`, `storage_key`
- **Producers:** Doc04/MediaPipeline
- **Consumers:** Doc09/Transcription

## `media.stored`

One media item passed the scan CLEAN and was stored (FR-10 step 5, T-04); only this branch ever writes a storage_key.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `index`
- **Producers:** Doc04/MediaPipeline
- **Consumers:** Doc07/Realtime, Doc15/Reporting

## `message.canceled`

A queued outbound was canceled PRE-SEND (FR-13; EC-04's blocked-between-compose-and-send is the first producer).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `reason`
- **Producers:** Doc04/DeliveryStateMachine
- **Consumers:** Doc07/Realtime

## `message.deleted`

A message was tombstoned (deleted_at) — staff still see the tombstone, content survives per retention (FR-21/FR-22, US-02).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`
- **Producers:** Doc04/MessageEditing
- **Consumers:** Doc07/Realtime, Doc17/Audit

## `message.delivered`

A channel receipt confirmed delivery — only where capability provides receipts (FR-13; Doc 05 defines the Telegram mapping).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`
- **Producers:** Doc04/DeliveryStateMachine
- **Consumers:** Doc07/Realtime, Doc15/Reporting

## `message.edited`

A message body was revised — the prior content moved into message_revisions, never overwritten (FR-21/FR-22, BR-03).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`
- **Producers:** Doc04/MessageEditing
- **Consumers:** Doc07/Realtime, Doc17/Audit

## `message.failed`

A send reached failed_final — non-retryable class immediately, or the FR-13 backoff exhausted (×5). Retryable interim failures are event-silent by design.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `reason`
- **Producers:** Doc04/DeliveryStateMachine
- **Consumers:** Doc07/Realtime, Doc15/Reporting, Doc18/Alerting

## `message.queued`

An outbound message entered `queued` (FR-12 compose, or a failed_final manual retry re-entering — FR-13).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`
- **Producers:** Doc04/ComposeOutbound, Doc04/DeliveryStateMachine
- **Consumers:** Doc07/Realtime, Doc06/TicketRouting

## `message.received`

An inbound customer message persisted with its row (Doc 04 FR-10 step 4) — THE fan-out signal; quarantined rows (FR-11) never emit it.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `customer_id`, `kind`
- **Producers:** Doc04/InboundPersister
- **Consumers:** Doc09/AiTurnDecision, Doc06/TicketReopenConsumer, Doc07/Realtime, Doc07/UnreadCounters, Doc08/Rollups

## `message.sent`

The adapter accepted the send (FR-13). Terminal-success where the channel has no receipts; NEVER rolls back (EC-05).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`
- **Producers:** Doc04/DeliveryStateMachine
- **Consumers:** Doc07/Realtime, Doc15/Reporting

## `note.added`

A staff member added an internal note (FR-17). The body never rides the bus and the note itself is structurally undeliverable (SEC-07/BR-01).

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `author_id`
- **Producers:** Doc04/NotesWriter
- **Consumers:** Doc07/Realtime, Doc17/Audit

## `note.mentioned`

An @mention inside a note named a staff member (FR-17; event name filed as interpretation A-4) — the notification consumer is Doc 07/18's.

- **Schema version:** 1
- **Payload:** `tenant_id`, `message_id`, `conversation_id`, `author_id`, `mentioned_user_id`
- **Producers:** Doc04/NotesWriter
- **Consumers:** Doc07/Notifications, Doc18/NotificationDelivery

## `rbac.highrisk.confirmed`

A DISTINCT second admin approved a pending high-risk grant (T-06, ruling R-9) and it took effect.

- **Schema version:** 1
- **Payload:** `tenant_id`, `pending_grant_id`, `permission`, `actor_id`
- **Producers:** Doc03/FourEyes
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `rbac.highrisk.granted`

A high-risk permission was granted (FR-08b): applied directly, or written pending when rbac.four_eyes is on — `pending` says which. Feeds SEC-03's audit view and US-05's monthly report.

- **Schema version:** 1
- **Payload:** `tenant_id`, `permission`, `subject_type`, `subject_id`, `actor_id`, `pending`
- **Producers:** Doc03/RoleAdministration, Doc03/OverrideAdministration
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring, Doc15/Reporting

## `rbac.highrisk.rejected`

A second admin rejected a pending high-risk grant; nothing was ever applied (FS-04's fail-safe posture).

- **Schema version:** 1
- **Payload:** `tenant_id`, `pending_grant_id`, `permission`, `actor_id`
- **Producers:** Doc03/FourEyes
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `rbac.override.added`

A per-user allow/deny exception was granted (FR-04); the mandatory reason is in the audit entry (SEC-04).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `permission`, `effect`, `actor_id`
- **Producers:** Doc03/OverrideAdministration
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `rbac.override.expired`

The cleanup pass tidied an override whose expires_at had passed (FR-09/AC-07). Enforcement never waited for this — the resolver's DB-time check already denied.

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `permission`
- **Producers:** Doc03/ExpireOverrides
- **Consumers:** Doc17/Audit

## `rbac.override.removed`

An override was revoked by an admin before any expiry.

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `permission`, `actor_id`
- **Producers:** Doc03/OverrideAdministration
- **Consumers:** Doc17/Audit

## `rbac.role.created`

A role was created — by an admin or by the tenant-provisioning seeder (FR-10).

- **Schema version:** 1
- **Payload:** `tenant_id`, `role_id`, `actor_id`
- **Producers:** Doc03/RoleAdministration, Doc03/RoleSeeder
- **Consumers:** Doc17/Audit

## `rbac.role.deleted`

A role was deleted (soft, ruling R-11 — grant physics forbid hard deletes) after FR-08c's nobody-assigned guard passed.

- **Schema version:** 1
- **Payload:** `tenant_id`, `role_id`, `actor_id`
- **Producers:** Doc03/RoleAdministration
- **Consumers:** Doc17/Audit

## `rbac.role.updated`

A role's grants, scopes or fields changed; version is EC-03's optimistic lock after the write. Capability caches for every holder are invalidated (FR-07).

- **Schema version:** 1
- **Payload:** `tenant_id`, `role_id`, `actor_id`, `version`
- **Producers:** Doc03/RoleAdministration
- **Consumers:** Doc17/Audit

## `rbac.user.role_changed`

A user was moved between roles (BR-01: exactly one). Queued work re-resolves at execution, so post-change rights govern (SEC-06).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `old_role_id`, `new_role_id`, `actor_id`
- **Producers:** Doc03/RoleAdministration
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `retention.completed`

A retention run finished (T-08/AC-09): counts per tenant; held rows moved to hold storage before their partition dropped (EC-09).

- **Schema version:** 1
- **Payload:** `tenant_id`, `partitions_dropped`, `messages_held`, `messages_removed`
- **Producers:** Doc04/MessagesRetention
- **Consumers:** Doc15/Reporting, Doc17/Audit, Doc18/Alerting

## `telegram.bot_blocked`

FR-15: the customer blocked the bot in direct mode (my_chat_member → kicked); future sends classify blocked_by_user. Two events, not one — the A-8 follower precedent (S23 plan resolution ii).

- **Schema version:** 1
- **Payload:** `tenant_id`, `bot_id`, `external_chat_id`
- **Producers:** Doc05/RouteTelegramUpdate
- **Consumers:** Doc07/Inbox, Doc15/Reporting

## `telegram.bot_unblocked`

FR-15: the customer unblocked the bot (my_chat_member → member).

- **Schema version:** 1
- **Payload:** `tenant_id`, `bot_id`, `external_chat_id`
- **Producers:** Doc05/RouteTelegramUpdate
- **Consumers:** Doc07/Inbox, Doc15/Reporting

## `telegram.connection.active`

FR-09: a business connection became active (admin-confirmed, SEC-04, or resumed from paused). Three connection events, not one — the S23 plan resolution (i).

- **Schema version:** 1
- **Payload:** `tenant_id`, `bot_id`, `connection_id`, `account_user_id`
- **Producers:** Doc05/ConnectionLifecycle
- **Consumers:** Doc06/Routing, Doc07/Inbox, Doc15/Reporting

## `telegram.connection.notified`

FR-09/FS-04: an admin notification for a paused|revoked transition; delivery is immediate or digest (flap damping >3/hour), the STATE stays truthful either way.

- **Schema version:** 1
- **Payload:** `tenant_id`, `connection_id`, `state`, `delivery`
- **Producers:** Doc05/ConnectionLifecycle
- **Consumers:** Doc07/Inbox

## `telegram.connection.paused`

FR-09/EC-03: a connection paused (can_reply=false or owner-side pause); inbound still flows, outbound frozen.

- **Schema version:** 1
- **Payload:** `tenant_id`, `bot_id`, `connection_id`, `account_user_id`
- **Producers:** Doc05/ConnectionLifecycle
- **Consumers:** Doc06/Routing, Doc07/Inbox, Doc15/Reporting

## `telegram.connection.revoked`

FR-09/FR-10: the owner disconnected; open tickets flag channel_unreachable and the outbound lane freezes.

- **Schema version:** 1
- **Payload:** `tenant_id`, `bot_id`, `connection_id`, `account_user_id`
- **Producers:** Doc05/ConnectionLifecycle
- **Consumers:** Doc06/Routing, Doc07/Inbox, Doc15/Reporting

## `telegram.handoff_requested`

FR-25: /human — the customer asked for a human. The Doc 09/06 handoff seam acts on it. Name filed as A-24 (the trigger event is unnamed by Doc 09 FR-12 / Doc 06).

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `external_chat_id`
- **Producers:** Doc05/CommandHandler
- **Consumers:** Doc09/AI, Doc06/Routing

## `telegram.window.closing`

FR-22: the reply window entered the closing band (≤2 h). Doc 12 automations may act (queue-priority boost); Doc 07 shows the countdown chip.

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `expires_at`
- **Producers:** Doc05/WindowSweep
- **Consumers:** Doc07/Inbox, Doc12/Automation

## `telegram.window.expired`

FR-22: the reply window closed. The composer disables (Doc 07); a customer message reopens it (FR-24).

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `expires_at`
- **Producers:** Doc05/WindowSweep
- **Consumers:** Doc07/Inbox, Doc12/Automation

## `telegram.window.reopened`

FR-24: a customer message reopened an expired window — recomputed expiry, badges cleared; assignee_id is the open ticket's assignee to notify (null if none). Doc 07 delivers the notification.

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `expires_at`, `assignee_id`
- **Producers:** Doc05/WindowReopenConsumer
- **Consumers:** Doc07/Inbox

## `tenant.provisioned`

A tenant completed every provisioning step and became active.

- **Schema version:** 1
- **Payload:** `tenant_id`
- **Producers:** Doc01/ProvisionTenant
- **Consumers:** Doc03/RoleSeeder, Doc10/KnowledgeBaseSeeder, Doc17/Audit

## `tenant.quota_exceeded`

A tenant hit a quota ceiling and a request was refused with 429.

- **Schema version:** 1
- **Payload:** `tenant_id`, `quota`
- **Producers:** Doc01/QuotaManager
- **Consumers:** Doc16/Backoffice, Doc18/Alerting

## `tenant.quota_threshold_reached`

A tenant crossed a quota warning threshold (NFR-06: alert at 80%).

- **Schema version:** 1
- **Payload:** `tenant_id`, `quota`, `threshold_percent`
- **Producers:** Doc01/QuotaManager
- **Consumers:** Doc16/Backoffice, Doc18/Alerting

## `tenant.setting_changed`

A tenant setting override was written; consumers should invalidate caches.

- **Schema version:** 1
- **Payload:** `tenant_id`, `key`
- **Producers:** Doc01/SettingsRepository
- **Consumers:** Doc01/SettingsCache, Doc16/Backoffice, Doc17/Audit

## `tenant.state_changed`

A tenant moved between lifecycle states (Doc 01 FR-05).

- **Schema version:** 1
- **Payload:** `tenant_id`, `old_state`, `new_state`
- **Producers:** Doc01/TenantLifecycle
- **Consumers:** Doc02/SessionInvalidation, Doc16/Backoffice, Doc17/Audit

## `ticket.assigned`

FR-15: a ticket changed hands, including to nobody — a null new_assignee_id IS the unassign that returns it to Doc 11's queue.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `old_assignee_id`, `new_assignee_id`, `by`
- **Producers:** Doc06/AssignTicket
- **Consumers:** Doc07/Inbox, Doc11/Routing, Doc12/SlaAutomation, Doc15/Reporting, Doc16/Notifications, Doc17/Audit, Doc08/SearchIndexer

## `ticket.assignee_deactivated`

EC-06: an open-family ticket was handed back because its assignee was deactivated — one event PER TICKET so Doc 11 re-routes each on its own merits. Name filed as interpretation A-8 (iii): EC-06 requires this event and FR-26's list does not name it.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `previous_assignee_id`
- **Producers:** Doc06/AssigneeDeactivated
- **Consumers:** Doc11/Routing, Doc07/Inbox, Doc17/Audit, Doc08/SearchIndexer

## `ticket.bulk_completed`

FR-25: a bulk batch finished — per-item partial success, the summary carries ok count and failed[] with per-item stable reasons. SEC-03's bulk-summary audit entry references the same job row.

- **Schema version:** 1
- **Payload:** `tenant_id`, `bulk_job_id`, `action`, `ok`, `failed`, `requested_by`
- **Producers:** Doc06/TicketBulkJob
- **Consumers:** Doc07/Inbox, Doc15/Reporting, Doc17/Audit

## `ticket.closed`

FR-07: a ticket entered `closed` — the auto-close sweep in the ordinary case (actor_type=system). The FR-08 reopen window runs from the closed_at this transition stamped.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `actor_type`, `actor_id`
- **Producers:** Doc06/TicketTransitionService
- **Consumers:** Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit

## `ticket.created`

A ticket was created through one of FR-12/13/14's doors or FR-11's handoff; `source` says which. Routing (Doc 11) and the SLA clock (Doc 12) start from here.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `customer_id`, `source`, `actor_type`, `actor_id`
- **Producers:** Doc06/CreateTicket
- **Consumers:** Doc07/Inbox, Doc11/Routing, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit, Doc08/Rollups, Doc08/SearchIndexer

## `ticket.deescalated`

FR-23: an escalated ticket returned to `open` with its de-escalation note. The current escalation payload cleared; history lives in the event stream.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `previous_path`, `actor_type`, `actor_id`
- **Producers:** Doc06/EscalateTicket
- **Consumers:** Doc16/Notifications, Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit

## `ticket.escalated`

FR-23: a ticket entered `escalated` with its mandatory ≥10-char reason. The escalation SLA clock (Doc 12) starts here; the original assignee has auto-followed.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `path`, `target_id`, `reason`, `actor_type`, `actor_id`
- **Producers:** Doc06/EscalateTicket
- **Consumers:** Doc16/Notifications, Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit

## `ticket.escalation_rerouted`

EC-05: a named escalation target was deactivated after the fact — the escalation fell back to supervisor_review with a system note, and the escalator is to be notified.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `from_target_user_id`, `notify_user_id`, `reason`
- **Producers:** Doc06/EscalateTicket
- **Consumers:** Doc16/Notifications, Doc07/Inbox, Doc17/Audit

## `ticket.follower_added`

FR-18: someone follows this ticket — themselves (tickets.follow), added by an assigner, or auto-followed as the escalating assignee (FR-23).

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `user_id`, `by`
- **Producers:** Doc06/TicketFollowers
- **Consumers:** Doc07/Inbox, Doc16/Notifications, Doc17/Audit

## `ticket.follower_removed`

FR-18: someone stopped following. Doc 16 stops notifying them; the history stays in the events (A-8 i).

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `user_id`, `by`
- **Producers:** Doc06/TicketFollowers
- **Consumers:** Doc07/Inbox, Doc16/Notifications, Doc17/Audit

## `ticket.merged`

FR-20: a source ticket was merged into a target (same customer, hard rule SEC-06). The source is closed with merged_into set and frozen (A-14); anchors moved, followers+tags unioned; reversible 24 h via unmerge.

- **Schema version:** 1
- **Payload:** `tenant_id`, `source_ticket_id`, `target_ticket_id`, `source_conversation_id`, `target_conversation_id`, `actor_type`, `actor_id`
- **Producers:** Doc06/MergeTickets
- **Consumers:** Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit

## `ticket.priority_changed`

FR-17: priority moved. Doc 12 decides whether its SLA policy re-targets — that decision is deliberately NOT made here. A no-op change emits nothing.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `old_priority`, `new_priority`, `actor_type`, `actor_id`
- **Producers:** Doc06/ChangePriority
- **Consumers:** Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit

## `ticket.reopened`

FR-08: a resolved or closed ticket returned to `open` — a customer message within the window (actor_type=system) or a staff reopen with reason (actor_type=staff). reopened_count has already been incremented.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `from_status`, `reopened_count`, `assignee_id`, `actor_type`, `actor_id`
- **Producers:** Doc06/TicketTransitionService
- **Consumers:** Doc07/Inbox, Doc11/Routing, Doc12/SlaAutomation, Doc15/Reporting, Doc16/Notifications, Doc17/Audit

## `ticket.resolved`

FR-06: a ticket entered `resolved`. Starts the FR-07 auto-close timer (armed in the same transaction), the Doc 12 CSAT flow and Doc 14's ticket_resolved SMS.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `actor_type`, `actor_id`
- **Producers:** Doc06/TicketTransitionService
- **Consumers:** Doc12/CsatFlow, Doc14/Sms, Doc07/Inbox, Doc15/Reporting, Doc17/Audit

## `ticket.spam_marked`

FR-09: a ticket was marked spam (reason on the audit entry, SEC-03). Excluded from SLA/CSAT/agent metrics while in this status (N-14); unmarkable for 7 days, terminal after (EC-09).

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `conversation_blocked`, `actor_type`, `actor_id`
- **Producers:** Doc06/SpamTicket
- **Consumers:** Doc15/Reporting, Doc07/Inbox, Doc17/Audit, Doc08/Rollups

## `ticket.spam_unmarked`

FR-09: a spam mark was reversed within its 7-day window — the prior status (new|open, A-10) restored from the event history, metrics re-included by the same one predicate that excluded them.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `restored_status`, `actor_type`, `actor_id`
- **Producers:** Doc06/SpamTicket
- **Consumers:** Doc15/Reporting, Doc07/Inbox, Doc17/Audit, Doc08/Rollups

## `ticket.split`

FR-21: selected messages were split into a linked-work ticket in the same conversation (origin_ticket_id set, cap 3 live per conversation — EC-04). The new ticket also emits its ordinary ticket.created.

- **Schema version:** 1
- **Payload:** `tenant_id`, `origin_ticket_id`, `new_ticket_id`, `conversation_id`, `message_count`, `actor_type`, `actor_id`
- **Producers:** Doc06/SplitTicket
- **Consumers:** Doc07/Inbox, Doc11/Routing, Doc15/Reporting, Doc17/Audit

## `ticket.status_changed`

FR-04: a ticket moved between statuses, with old/new and the acting class. THE transition signal — SLA, automation, routing, metrics and audit all hang off it. A same-state call (EC-07) changes nothing and emits nothing.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `old_status`, `new_status`, `actor_type`, `actor_id`
- **Producers:** Doc06/TicketTransitionService
- **Consumers:** Doc07/Inbox, Doc11/Routing, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit, Doc08/Rollups

## `ticket.subject_changed`

FR-03: a subject was written — by Doc 09 within 30 s (subject_source=ai) or edited by staff under tickets.edit. A failed generation emits NOTHING and leaves the subject NULL (EC-11).

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `subject_source`, `actor_type`, `actor_id`
- **Producers:** Doc06/SubjectWriter
- **Consumers:** Doc07/Inbox, Doc17/Audit

## `ticket.tagged`

FR-19: tags were added. The tags themselves are in the audit entry — free text an agent typed does not belong on a retained event stream.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `count`, `actor_id`
- **Producers:** Doc06/TicketTags
- **Consumers:** Doc07/Inbox, Doc15/Reporting, Doc17/Audit

## `ticket.transferred`

FR-22: a ticket changed department. Doc 11 re-routes off this; Doc 12 decides SLA re-targeting off this (the instance keeps original targets unless policy says otherwise); status untouched.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `old_department_id`, `new_department_id`, `assignee_retained`, `actor_type`, `actor_id`
- **Producers:** Doc06/TransferTicket
- **Consumers:** Doc11/Routing, Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit, Doc08/SearchIndexer

## `ticket.unmerged`

FR-20/AC-04: a merge link was reversed within its 24 h window — the source restored byte-identically from the snapshot (status, assignee, timer, anchors; target sets reset).

- **Schema version:** 1
- **Payload:** `tenant_id`, `source_ticket_id`, `target_ticket_id`, `restored_status`, `actor_type`, `actor_id`
- **Producers:** Doc06/UnmergeTicket
- **Consumers:** Doc07/Inbox, Doc12/SlaAutomation, Doc15/Reporting, Doc17/Audit

## `ticket.untagged`

FR-19: tags were removed.

- **Schema version:** 1
- **Payload:** `tenant_id`, `ticket_id`, `conversation_id`, `count`, `actor_id`
- **Producers:** Doc06/TicketTags
- **Consumers:** Doc07/Inbox, Doc15/Reporting, Doc17/Audit

## `transcript.exported`

A watermarked conversation transcript was produced (FR-24, SEC-05) — notes excluded unless double-confirmed.

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `exporter_id`, `format`
- **Producers:** Doc04/TranscriptExporter
- **Consumers:** Doc17/Audit, Doc15/Reporting
