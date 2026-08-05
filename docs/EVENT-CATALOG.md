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

48 event(s).

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
- **Consumers:** Doc09/AiTurnDecision, Doc06/TicketRouting, Doc07/Realtime, Doc07/UnreadCounters

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

## `transcript.exported`

A watermarked conversation transcript was produced (FR-24, SEC-05) — notes excluded unless double-confirmed.

- **Schema version:** 1
- **Payload:** `tenant_id`, `conversation_id`, `exporter_id`, `format`
- **Producers:** Doc04/TranscriptExporter
- **Consumers:** Doc17/Audit, Doc15/Reporting
