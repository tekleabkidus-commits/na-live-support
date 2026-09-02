<?php

declare(strict_types=1);

namespace App\Support\Events;

use InvalidArgumentException;

/**
 * The living event catalog (Doc 01 FR-08, §21; R-02; SEC-07).
 *
 * Doc 01 §21: "Document every published event in a living catalog (name, payload
 * schema, producers, consumers) checked in CI — this catalog becomes the
 * integration contract for Docs 04–17."
 *
 * Code is the source of truth and docs/EVENT-CATALOG.md is generated from it, not
 * the other way round. A catalog maintained by hand beside the code drifts from it
 * within weeks, and a drifted integration contract is worse than none — R-02's
 * "event bus becomes implicit coupling" begins exactly there. `ci/event-catalog.php`
 * regenerates and fails the build on any difference, so the document cannot rot.
 *
 * NOT A SEVENTH REGISTRY (owner ruling D-5). CLAUDE.md §2 fixes the registries at
 * six; this is a code-side contract with a generated document and a gate.
 *
 * SEC-07 IS ENFORCED HERE, NOT TRUSTED. Each event declares its exact payload
 * keys, and `publish()` rejects anything else — extra keys as well as missing
 * ones. "Outbox payloads exclude PII beyond ids wherever a subscriber can re-fetch
 * by id" is otherwise a rule every future publisher has to remember; declaring the
 * shape makes forgetting it a test failure
 * (tests/Security/OutboxPayloadMinimizationTest.php).
 *
 * `consumers` is documentation of intent — Docs 04–17 register themselves as they
 * land — and is why a producer can tell, before changing a payload, who will
 * notice.
 */
final class EventCatalog
{
    /**
     * @var array<string, array{
     *     schema_version: int,
     *     payload: list<string>,
     *     producers: list<string>,
     *     consumers: list<string>,
     *     description: string,
     * }>
     */
    public const EVENTS = [
        'tenant.provisioned' => [
            'schema_version' => 1,
            'payload' => ['tenant_id'],
            'producers' => ['Doc01/ProvisionTenant'],
            'consumers' => ['Doc03/RoleSeeder', 'Doc10/KnowledgeBaseSeeder', 'Doc12/CustomerFieldSeeder', 'Doc17/Audit'],
            'description' => 'A tenant completed every provisioning step and became active.',
        ],
        'tenant.state_changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'old_state', 'new_state'],
            'producers' => ['Doc01/TenantLifecycle'],
            'consumers' => ['Doc02/SessionInvalidation', 'Doc16/Backoffice', 'Doc17/Audit'],
            'description' => 'A tenant moved between lifecycle states (Doc 01 FR-05).',
        ],
        'tenant.setting_changed' => [
            'schema_version' => 1,
            // No `old_value`/`new_value`: a setting value may be an
            // encrypted-string, and SEC-07 plus C-13 both say the transport is the
            // wrong place for it. The old→new pair lives in audit_log, which is
            // access-controlled and append-only; a subscriber that needs the value
            // reads it through the settings engine, by key.
            'payload' => ['tenant_id', 'key'],
            'producers' => ['Doc01/SettingsRepository'],
            'consumers' => ['Doc01/SettingsCache', 'Doc16/Backoffice', 'Doc17/Audit'],
            'description' => 'A tenant setting override was written; consumers should invalidate caches.',
        ],
        'tenant.quota_threshold_reached' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'quota', 'threshold_percent'],
            'producers' => ['Doc01/QuotaManager'],
            'consumers' => ['Doc16/Backoffice', 'Doc18/Alerting'],
            'description' => 'A tenant crossed a quota warning threshold (NFR-06: alert at 80%).',
        ],
        'tenant.quota_exceeded' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'quota'],
            'producers' => ['Doc01/QuotaManager'],
            'consumers' => ['Doc16/Backoffice', 'Doc18/Alerting'],
            'description' => 'A tenant hit a quota ceiling and a request was refused with 429.',
        ],

        /*
        |----------------------------------------------------------------------
        | Doc 02 §4.8 — auth events
        |----------------------------------------------------------------------
        |
        | Doc 02 §21 says to freeze these names now, because Docs 15/17/18 build
        | reports, audit views and alerts against them.
        |
        | PAYLOADS ARE IDENTIFIERS ONLY (owner ruling; SEC-07, SEC-08, C-13).
        | No email addresses, no user agents, no tokens, no codes. An address is
        | itself identifying, and an event bus fans out to consumers with
        | different retention and access rules than the audit log — so anything
        | a consumer genuinely needs beyond an id, it fetches by id under its own
        | authorisation. The publisher REJECTS extra keys, so this is enforced
        | rather than encouraged.
        |
        | The one apparent exception is `ip_hash` on auth.login.failed, and it is
        | not an exception: it is a salted hash, present so security monitoring
        | (Doc 18) can correlate "same source, many accounts" without the bus
        | carrying a personal datum. The raw IP lives on the device record and in
        | the audit entry, both tenant-scoped and access-controlled.
        */

        'auth.login.succeeded' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'device_id', 'new_device'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A staff user completed authentication and a session was established (FR-04).',
        ],
        'auth.login.failed' => [
            'schema_version' => 1,
            // user_id is present ONLY when the attempt identified an account; a
            // miss on a non-existent address publishes nothing at all, because
            // there is no tenant to publish it under — that case is a metric.
            'payload' => ['tenant_id', 'user_id', 'reason', 'ip_hash'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A staff authentication attempt failed, with FR-04\'s reason category.',
        ],
        'auth.logout' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A staff user ended their session deliberately (§4.8).',
        ],
        'auth.2fa.enabled' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A user confirmed 2FA enrolment with a valid code (FR-06).',
        ],
        'auth.2fa.disabled' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A user turned their own 2FA off, proving a current code first (§4.8).',
        ],
        'auth.2fa.reset_by_admin' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'admin_id'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'An admin cleared a user\'s 2FA; the user re-enrols at next login (FR-08).',
        ],
        'auth.2fa.challenge_failed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'attempt'],
            'producers' => ['Doc02/TwoFactor'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A 2FA challenge attempt failed; the fifth voids the login attempt (FR-07).',
        ],

        // ── Doc 02 §4.4: the password lifecycle (§4.8's three password events).
        // Identifiers only, per the LoginRecorder rule: never the address the
        // request named, never anything token-derived (SEC-07, SEC-08).
        'auth.password.reset_requested' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id'],
            'producers' => ['Doc02/PasswordLifecycle'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A reset was requested and a token minted for THIS account; one event per matched account (FR-16).',
        ],
        'auth.password.reset_completed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'origin'],
            'producers' => ['Doc02/PasswordLifecycle'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A reset token was redeemed: new password set, all sessions revoked, this account unlocked (FR-16, EC-11, R-6).',
        ],
        'auth.password.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'origin'],
            'producers' => ['Doc02/PasswordLifecycle'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A password was set outside the reset flow: authenticated self-change (FR-14) or an invitation\'s first password (FR-17); `origin` says which.',
        ],

        // ── Doc 02 §4.5: API tokens (§4.8's three token events). Identifier-only
        // BY RULING (step 9): tenant, user and token ids — never scope lists,
        // names or anything plaintext-derived. The one addition is `permission`
        // on scope_denied, because "denied WHAT" is the datum Doc 18's
        // monitoring alerts on (R-03 token-sprawl reviews consume it too), and
        // a permission KEY identifies a catalog entry, not a person.
        'auth.token.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'token_id'],
            'producers' => ['Doc02/TokenMinter'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'An API token was minted — personal, machine, or a rotation\'s replacement (FR-18/FR-19); `user_id` is the owner (a service account for machine tokens, BR-03).',
        ],
        'auth.token.revoked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'token_id'],
            'producers' => ['Doc02/TokenRevoker'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'An API token was revoked — self-service, admin action, or BR-04\'s disable cascade; the audit entry carries which.',
        ],
        'auth.ip.blocked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'ip_hash'],
            'producers' => ['Doc02/LoginPipeline'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A correct credential was refused because the role\'s IP allowlist excludes the address (SEC-05; user-facing error stays generic per FR-02).',
        ],
        'auth.token.scope_denied' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'token_id', 'permission'],
            'producers' => ['Doc02/RequirePermission'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A token-authenticated request failed the T-05 intersection for the named permission (FR-20 insufficient_scope, AC-05).',
        ],

        // ── Doc 03 — RBAC (FR-12, T-07; S19). Closed payloads, identifiers
        // only — old/new DIFFS live in the audit entries, never in the
        // 13-month event stream (SEC-07; same split as Doc 02's Q7 ruling).
        'rbac.role.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'role_id', 'actor_id'],
            'producers' => ['Doc03/RoleAdministration', 'Doc03/RoleSeeder'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A role was created — by an admin or by the tenant-provisioning seeder (FR-10).',
        ],
        'rbac.role.updated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'role_id', 'actor_id', 'version'],
            'producers' => ['Doc03/RoleAdministration'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A role\'s grants, scopes or fields changed; version is EC-03\'s optimistic lock after the write. Capability caches for every holder are invalidated (FR-07).',
        ],
        'rbac.role.deleted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'role_id', 'actor_id'],
            'producers' => ['Doc03/RoleAdministration'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'A role was deleted (soft, ruling R-11 — grant physics forbid hard deletes) after FR-08c\'s nobody-assigned guard passed.',
        ],
        'rbac.user.role_changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'old_role_id', 'new_role_id', 'actor_id'],
            'producers' => ['Doc03/RoleAdministration'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A user was moved between roles (BR-01: exactly one). Queued work re-resolves at execution, so post-change rights govern (SEC-06).',
        ],
        'rbac.override.added' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'permission', 'effect', 'actor_id'],
            'producers' => ['Doc03/OverrideAdministration'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A per-user allow/deny exception was granted (FR-04); the mandatory reason is in the audit entry (SEC-04).',
        ],
        'rbac.override.removed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'permission', 'actor_id'],
            'producers' => ['Doc03/OverrideAdministration'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'An override was revoked by an admin before any expiry.',
        ],
        'rbac.override.expired' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'user_id', 'permission'],
            'producers' => ['Doc03/ExpireOverrides'],
            'consumers' => ['Doc17/Audit'],
            'description' => 'The cleanup pass tidied an override whose expires_at had passed (FR-09/AC-07). Enforcement never waited for this — the resolver\'s DB-time check already denied.',
        ],
        'rbac.highrisk.granted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'permission', 'subject_type', 'subject_id', 'actor_id', 'pending'],
            'producers' => ['Doc03/RoleAdministration', 'Doc03/OverrideAdministration'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring', 'Doc15/Reporting'],
            'description' => 'A high-risk permission was granted (FR-08b): applied directly, or written pending when rbac.four_eyes is on — `pending` says which. Feeds SEC-03\'s audit view and US-05\'s monthly report.',
        ],
        'rbac.highrisk.confirmed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'pending_grant_id', 'permission', 'actor_id'],
            'producers' => ['Doc03/FourEyes'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A DISTINCT second admin approved a pending high-risk grant (T-06, ruling R-9) and it took effect.',
        ],
        'rbac.highrisk.rejected' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'pending_grant_id', 'permission', 'actor_id'],
            'producers' => ['Doc03/FourEyes'],
            'consumers' => ['Doc17/Audit', 'Doc18/SecurityMonitoring'],
            'description' => 'A second admin rejected a pending high-risk grant; nothing was ever applied (FS-04\'s fail-safe posture).',
        ],
        'conversation.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'customer_id', 'channel'],
            'producers' => ['Doc04/InboundPersister'],
            'consumers' => ['Doc06/TicketRouting', 'Doc07/Inbox'],
            'description' => 'First contact created THE permanent conversation for {tenant, channel endpoint, external chat} (Doc 04 FR-01).',
        ],
        'message.received' => [
            'schema_version' => 1,
            // Identifiers only (SEC-07/Q7): the body never rides the bus —
            // subscribers re-fetch by id under their own permission checks.
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'customer_id', 'kind'],
            'producers' => ['Doc04/InboundPersister'],
            // Doc06/TicketReopenConsumer is the first DURABLE consumer (ADR 0011):
            // the relay's dispatch map queues it with this event's id.
            'consumers' => ['Doc09/AiTurnDecision', 'Doc06/TicketReopenConsumer', 'Doc07/Realtime', 'Doc07/UnreadCounters', 'Doc08/Rollups'],
            'description' => 'An inbound customer message persisted with its row (Doc 04 FR-10 step 4) — THE fan-out signal; quarantined rows (FR-11) never emit it.',
        ],
        'message.queued' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id'],
            'producers' => ['Doc04/ComposeOutbound', 'Doc04/DeliveryStateMachine'],
            'consumers' => ['Doc07/Realtime', 'Doc06/TicketRouting'],
            'description' => 'An outbound message entered `queued` (FR-12 compose, or a failed_final manual retry re-entering — FR-13).',
        ],
        'message.sent' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id'],
            'producers' => ['Doc04/DeliveryStateMachine'],
            'consumers' => ['Doc07/Realtime', 'Doc15/Reporting'],
            'description' => 'The adapter accepted the send (FR-13). Terminal-success where the channel has no receipts; NEVER rolls back (EC-05).',
        ],
        'message.delivered' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id'],
            'producers' => ['Doc04/DeliveryStateMachine'],
            'consumers' => ['Doc07/Realtime', 'Doc15/Reporting'],
            'description' => 'A channel receipt confirmed delivery — only where capability provides receipts (FR-13; Doc 05 defines the Telegram mapping).',
        ],
        'message.failed' => [
            'schema_version' => 1,
            // `reason` is the FR-14 code (a DeliveryFailureReason value) the UI
            // maps to messaging.fail.* guidance — a stable identifier, not text.
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'reason'],
            'producers' => ['Doc04/DeliveryStateMachine'],
            'consumers' => ['Doc07/Realtime', 'Doc15/Reporting', 'Doc18/Alerting'],
            'description' => 'A send reached failed_final — non-retryable class immediately, or the FR-13 backoff exhausted (×5). Retryable interim failures are event-silent by design.',
        ],
        'message.canceled' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'reason'],
            'producers' => ['Doc04/DeliveryStateMachine'],
            'consumers' => ['Doc07/Realtime'],
            'description' => 'A queued outbound was canceled PRE-SEND (FR-13; EC-04\'s blocked-between-compose-and-send is the first producer).',
        ],
        'conversation.lane_skipped' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'message_id'],
            'producers' => ['Doc04/LaneWorker'],
            'consumers' => ['Doc07/Inbox', 'Doc18/Alerting'],
            'description' => 'FR-15\'s 10-minute head-block cap fired: the stuck head was demoted and the lane drains past it (event name filed as interpretation A-4).',
        ],
        'telegram.handoff_requested' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'external_chat_id'],
            'producers' => ['Doc05/CommandHandler'],
            'consumers' => ['Doc09/AI', 'Doc06/Routing'],
            'description' => 'FR-25: /human — the customer asked for a human. The Doc 09/06 handoff seam acts on it. Name filed as A-24 (the trigger event is unnamed by Doc 09 FR-12 / Doc 06).',
        ],
        'telegram.window.reopened' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'expires_at', 'assignee_id'],
            'producers' => ['Doc05/WindowReopenConsumer'],
            'consumers' => ['Doc07/Inbox'],
            'description' => 'FR-24: a customer message reopened an expired window — recomputed expiry, badges cleared; assignee_id is the open ticket\'s assignee to notify (null if none). Doc 07 delivers the notification.',
        ],
        'telegram.window.closing' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'expires_at'],
            'producers' => ['Doc05/WindowSweep'],
            'consumers' => ['Doc07/Inbox', 'Doc12/Automation'],
            'description' => 'FR-22: the reply window entered the closing band (≤2 h). Doc 12 automations may act (queue-priority boost); Doc 07 shows the countdown chip.',
        ],
        'telegram.window.expired' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'expires_at'],
            'producers' => ['Doc05/WindowSweep'],
            'consumers' => ['Doc07/Inbox', 'Doc12/Automation'],
            'description' => 'FR-22: the reply window closed. The composer disables (Doc 07); a customer message reopens it (FR-24).',
        ],
        'telegram.connection.active' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'bot_id', 'connection_id', 'account_user_id'],
            'producers' => ['Doc05/ConnectionLifecycle'],
            'consumers' => ['Doc06/Routing', 'Doc07/Inbox', 'Doc15/Reporting'],
            'description' => 'FR-09: a business connection became active (admin-confirmed, SEC-04, or resumed from paused). Three connection events, not one — the S23 plan resolution (i).',
        ],
        'telegram.connection.paused' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'bot_id', 'connection_id', 'account_user_id'],
            'producers' => ['Doc05/ConnectionLifecycle'],
            'consumers' => ['Doc06/Routing', 'Doc07/Inbox', 'Doc15/Reporting'],
            'description' => 'FR-09/EC-03: a connection paused (can_reply=false or owner-side pause); inbound still flows, outbound frozen.',
        ],
        'telegram.connection.revoked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'bot_id', 'connection_id', 'account_user_id'],
            'producers' => ['Doc05/ConnectionLifecycle'],
            'consumers' => ['Doc06/Routing', 'Doc07/Inbox', 'Doc15/Reporting'],
            'description' => 'FR-09/FR-10: the owner disconnected; open tickets flag channel_unreachable and the outbound lane freezes.',
        ],
        'telegram.connection.notified' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'connection_id', 'state', 'delivery'],
            'producers' => ['Doc05/ConnectionLifecycle'],
            'consumers' => ['Doc07/Inbox'],
            'description' => 'FR-09/FS-04: an admin notification for a paused|revoked transition; delivery is immediate or digest (flap damping >3/hour), the STATE stays truthful either way.',
        ],
        'telegram.bot_blocked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'bot_id', 'external_chat_id'],
            'producers' => ['Doc05/RouteTelegramUpdate'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting'],
            'description' => 'FR-15: the customer blocked the bot in direct mode (my_chat_member → kicked); future sends classify blocked_by_user. Two events, not one — the A-8 follower precedent (S23 plan resolution ii).',
        ],
        'telegram.bot_unblocked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'bot_id', 'external_chat_id'],
            'producers' => ['Doc05/RouteTelegramUpdate'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting'],
            'description' => 'FR-15: the customer unblocked the bot (my_chat_member → member).',
        ],
        'channel.health.changed' => [
            'schema_version' => 2,
            // `status` is healthy|degraded|down — an enum, not free text (Q7's
            // posture). v2 per ruling A-22 (S23 plan approval): Doc 05 FR-29's
            // tri-state vocabulary; the Doc 04 tracker emits healthy|down and
            // `degraded` arrives with Doc 05's threshold rollup producer.
            'payload' => ['tenant_id', 'channel', 'channel_ref_id', 'status'],
            'producers' => ['Doc04/ChannelHealthTracker', 'Doc05/HealthRollup'],
            // Doc07/Inbox joined at S07 step 3 — the event's FIRST consumer
            // (the FR-03 header indicator; contract fixture doc07-events.json).
            'consumers' => ['Doc05/ChannelSurfaces', 'Doc07/Inbox', 'Doc13/ChannelSurfaces', 'Doc18/Alerting'],
            'description' => 'FS-01\'s health signal, fired on TRANSITION only (A-22 is the canonical home of transition-only): a channel endpoint crossed a health threshold or recovered. Status vocabulary healthy|degraded|down (v2, ruling A-22). Event name filed as interpretation A-4.',
        ],
        'media.stored' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'index'],
            'producers' => ['Doc04/MediaPipeline'],
            'consumers' => ['Doc07/Realtime', 'Doc15/Reporting'],
            'description' => 'One media item passed the scan CLEAN and was stored (FR-10 step 5, T-04); only this branch ever writes a storage_key.',
        ],
        'media.infected' => [
            'schema_version' => 1,
            // The signature is the scanner's detection name — an identifier,
            // and the thing Doc 18's threshold alerting groups by (SEC-04).
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'index', 'signature'],
            'producers' => ['Doc04/MediaPipeline'],
            'consumers' => ['Doc07/Realtime', 'Doc18/SecurityMonitoring'],
            'description' => 'The scan found malware: item blocked with NO download path for any role (SEC-04); bytes never stored.',
        ],
        'media.needs_transcript' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'index', 'storage_key'],
            'producers' => ['Doc04/MediaPipeline'],
            'consumers' => ['Doc09/Transcription'],
            'description' => 'T-05 hook, emitted after media.stored for voice: Doc 09 transcribes and writes back transcript_ref; core stays provider-agnostic.',
        ],
        'retention.completed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'partitions_dropped', 'messages_held', 'messages_removed'],
            'producers' => ['Doc04/MessagesRetention'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit', 'Doc18/Alerting'],
            'description' => 'A retention run finished (T-08/AC-09): counts per tenant; held rows moved to hold storage before their partition dropped (EC-09).',
        ],
        'conversation.blocked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id'],
            'producers' => ['Doc04/ConversationBlocking'],
            'consumers' => ['Doc06/SpamDisposition', 'Doc07/Inbox'],
            'description' => 'A conversation was blocked (FR-03): outbound refuses (BR-02), inbound quarantines (FR-11). The reason lives in the audit entry.',
        ],
        'conversation.unblocked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id'],
            'producers' => ['Doc04/ConversationBlocking'],
            'consumers' => ['Doc06/SpamDisposition', 'Doc07/Inbox'],
            'description' => 'A blocked conversation returned to active. Nothing retro-fans-out (FR-11): quarantined rows stay off the bus, by construction.',
        ],
        'message.edited' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id'],
            'producers' => ['Doc04/MessageEditing'],
            'consumers' => ['Doc07/Realtime', 'Doc17/Audit'],
            'description' => 'A message body was revised — the prior content moved into message_revisions, never overwritten (FR-21/FR-22, BR-03).',
        ],
        'message.deleted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id'],
            'producers' => ['Doc04/MessageEditing'],
            'consumers' => ['Doc07/Realtime', 'Doc17/Audit'],
            'description' => 'A message was tombstoned (deleted_at) — staff still see the tombstone, content survives per retention (FR-21/FR-22, US-02).',
        ],
        'transcript.exported' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'exporter_id', 'format'],
            'producers' => ['Doc04/TranscriptExporter'],
            'consumers' => ['Doc17/Audit', 'Doc15/Reporting'],
            'description' => 'A watermarked conversation transcript was produced (FR-24, SEC-05) — notes excluded unless double-confirmed.',
        ],
        'note.added' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'author_id'],
            'producers' => ['Doc04/NotesWriter'],
            'consumers' => ['Doc07/Realtime', 'Doc17/Audit'],
            'description' => 'A staff member added an internal note (FR-17). The body never rides the bus and the note itself is structurally undeliverable (SEC-07/BR-01).',
        ],
        'note.mentioned' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'author_id', 'mentioned_user_id'],
            'producers' => ['Doc04/NotesWriter'],
            'consumers' => ['Doc07/Notifications', 'Doc18/NotificationDelivery'],
            'description' => 'An @mention inside a note named a staff member (FR-17; event name filed as interpretation A-4) — the notification consumer is Doc 07/18\'s.',
        ],
        'media.needs_ocr' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'message_id', 'conversation_id', 'index', 'storage_key'],
            'producers' => ['Doc04/MediaPipeline'],
            'consumers' => ['Doc09/Ocr'],
            'description' => 'T-05 hook, emitted after media.stored for photos: Doc 09 runs OCR and writes back ocr_ref; core stays provider-agnostic.',
        ],

        // ── Doc 06: the ticket event family (FR-26) ──────────────────────────
        // Registered per STEP, each with its full payload from the day it first
        // fires — never a payload that has to grow later, because a payload that
        // grows is a schema version bump for every consumer. The family's full
        // enumeration (23 events, per filed interpretation A-8) completes as its
        // operations land; step 2 ships the one the transition machine itself
        // emits.
        'ticket.status_changed' => [
            'schema_version' => 1,
            // Identifiers and status names only (Q7's posture): no subject, no
            // reason text. The reason lives in the audit entry, which has its own
            // retention and access gates — a 13-month event stream must not
            // accumulate free-text an agent typed about a player.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'old_status', 'new_status', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/TicketTransitionService'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit', 'Doc08/Rollups'],
            'description' => 'FR-04: a ticket moved between statuses, with old/new and the acting class. THE transition signal — SLA, automation, routing, metrics and audit all hang off it. A same-state call (EC-07) changes nothing and emits nothing.',
        ],
        'ticket.created' => [
            'schema_version' => 1,
            // `source` rides because FR-13 requires it recorded and Doc 15 counts by
            // it; the subject does not, being staff- or AI-authored free text.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'customer_id', 'source', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/CreateTicket'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit', 'Doc08/Rollups', 'Doc08/SearchIndexer', 'Doc09/GenerateTicketSubject'],
            'description' => 'A ticket was created through one of FR-12/13/14\'s doors or FR-11\'s handoff; `source` says which. Routing (Doc 11) and the SLA clock (Doc 12) start from here.',
        ],
        'ticket.subject_changed' => [
            'schema_version' => 1,
            // Identifiers and provenance only — the subject TEXT lives in the audit
            // entry (Q7): a 13-month stream must not accumulate what an agent typed.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'subject_source', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/SubjectWriter'],
            'consumers' => ['Doc07/Inbox', 'Doc17/Audit'],
            'description' => 'FR-03: a subject was written — by Doc 09 within 30 s (subject_source=ai) or edited by staff under tickets.edit. A failed generation emits NOTHING and leaves the subject NULL (EC-11).',
        ],
        'ticket.assigned' => [
            'schema_version' => 1,
            // FR-15 names this payload exactly: "{old, new, by}".
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'old_assignee_id', 'new_assignee_id', 'by'],
            'producers' => ['Doc06/AssignTicket'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc16/Notifications', 'Doc17/Audit', 'Doc08/SearchIndexer'],
            'description' => 'FR-15: a ticket changed hands, including to nobody — a null new_assignee_id IS the unassign that returns it to Doc 11\'s queue.',
        ],
        'ticket.priority_changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'old_priority', 'new_priority', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/ChangePriority'],
            'consumers' => ['Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-17: priority moved. Doc 12 decides whether its SLA policy re-targets — that decision is deliberately NOT made here. A no-op change emits nothing.',
        ],
        'ticket.follower_added' => [
            'schema_version' => 1,
            // A-8 (i): FR-26's `follower_added|removed` reads as TWO events.
            'payload' => ['tenant_id', 'ticket_id', 'user_id', 'by'],
            'producers' => ['Doc06/TicketFollowers'],
            'consumers' => ['Doc07/Inbox', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'FR-18: someone follows this ticket — themselves (tickets.follow), added by an assigner, or auto-followed as the escalating assignee (FR-23).',
        ],
        'ticket.follower_removed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'user_id', 'by'],
            'producers' => ['Doc06/TicketFollowers'],
            'consumers' => ['Doc07/Inbox', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'FR-18: someone stopped following. Doc 16 stops notifying them; the history stays in the events (A-8 i).',
        ],
        'ticket.tagged' => [
            'schema_version' => 1,
            // The tag TEXT is operator free-text about a player's issue and rides the
            // audit entry, never the 13-month stream (Q7). `count` is what a consumer
            // needs to know something changed.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'count', 'actor_id'],
            'producers' => ['Doc06/TicketTags'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-19: tags were added. The tags themselves are in the audit entry — free text an agent typed does not belong on a retained event stream.',
        ],
        'ticket.untagged' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'count', 'actor_id'],
            'producers' => ['Doc06/TicketTags'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-19: tags were removed.',
        ],
        'ticket.assignee_deactivated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'previous_assignee_id'],
            'producers' => ['Doc06/AssigneeDeactivated'],
            'consumers' => ['Doc11/Routing', 'Doc07/Inbox', 'Doc17/Audit', 'Doc08/SearchIndexer'],
            'description' => 'EC-06: an open-family ticket was handed back because its assignee was deactivated — one event PER TICKET so Doc 11 re-routes each on its own merits. Name filed as interpretation A-8 (iii): EC-06 requires this event and FR-26\'s list does not name it.',
        ],
        'ticket.resolved' => [
            'schema_version' => 1,
            // Emitted by the transition service ALONGSIDE ticket.status_changed on
            // every entry into `resolved`, whatever the door (resolve action, bulk,
            // automation) — SS-04's no-drift posture. FR-06 hangs the CSAT flow
            // (Doc 12) and the SMS notification off this; A-8 files that it DOUBLES
            // as Doc 14's `ticket_resolved` signal — no separate event exists.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/TicketTransitionService'],
            'consumers' => ['Doc12/CsatFlow', 'Doc14/Sms', 'Doc07/Inbox', 'Doc09/OutcomeEnrichment', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-06: a ticket entered `resolved`. Starts the FR-07 auto-close timer (armed in the same transaction), the Doc 12 CSAT flow and Doc 14\'s ticket_resolved SMS.',
        ],
        'ticket.closed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/TicketTransitionService'],
            'consumers' => ['Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-07: a ticket entered `closed` — the auto-close sweep in the ordinary case (actor_type=system). The FR-08 reopen window runs from the closed_at this transition stamped.',
        ],
        'ticket.reopened' => [
            'schema_version' => 1,
            // `from_status` is resolved|closed — the two FR-08 doors. `assignee_id`
            // is the POST-reopen outcome: the retained prior assignee, or null when
            // they were inactive and the ticket went back to Doc 11's queue.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'from_status', 'reopened_count', 'assignee_id', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/TicketTransitionService'],
            'consumers' => ['Doc07/Inbox', 'Doc09/OutcomeEnrichment', 'Doc11/Routing', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'FR-08: a resolved or closed ticket returned to `open` — a customer message within the window (actor_type=system) or a staff reopen with reason (actor_type=staff). reopened_count has already been incremented.',
        ],
        'ticket.merged' => [
            'schema_version' => 1,
            // One event PER LINK carrying both sides — FR-20's "events on both":
            // Doc 07 renders it on either timeline; Doc 12 closes the source's SLA
            // instances superseded off exactly this signal.
            'payload' => ['tenant_id', 'source_ticket_id', 'target_ticket_id', 'source_conversation_id', 'target_conversation_id', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/MergeTickets'],
            'consumers' => ['Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-20: a source ticket was merged into a target (same customer, hard rule SEC-06). The source is closed with merged_into set and frozen (A-14); anchors moved, followers+tags unioned; reversible 24 h via unmerge.',
        ],
        'ticket.unmerged' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'source_ticket_id', 'target_ticket_id', 'restored_status', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/UnmergeTicket'],
            'consumers' => ['Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-20/AC-04: a merge link was reversed within its 24 h window — the source restored byte-identically from the snapshot (status, assignee, timer, anchors; target sets reset).',
        ],
        'ticket.split' => [
            'schema_version' => 1,
            // The anchors resolving to the exact selection live in
            // ticket_message_anchors (kind split_selection) — the event says a split
            // happened; the rows say precisely which messages.
            'payload' => ['tenant_id', 'origin_ticket_id', 'new_ticket_id', 'conversation_id', 'message_count', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/SplitTicket'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-21: selected messages were split into a linked-work ticket in the same conversation (origin_ticket_id set, cap 3 live per conversation — EC-04). The new ticket also emits its ordinary ticket.created.',
        ],
        'ticket.transferred' => [
            'schema_version' => 1,
            // `assignee_retained` is the FR-22 outcome Doc 11 routes on: false means
            // the ticket went back to the queue with its department change.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'old_department_id', 'new_department_id', 'assignee_retained', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/TransferTicket'],
            'consumers' => ['Doc11/Routing', 'Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit', 'Doc08/SearchIndexer'],
            'description' => 'FR-22: a ticket changed department. Doc 11 re-routes off this; Doc 12 decides SLA re-targeting off this (the instance keeps original targets unless policy says otherwise); status untouched.',
        ],
        'ticket.escalated' => [
            'schema_version' => 1,
            // `target_id` is null for supervisor_review; Doc 16 notifies the target
            // (or the supervisor queue) off path + target.
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'path', 'target_id', 'reason', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/EscalateTicket'],
            'consumers' => ['Doc16/Notifications', 'Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-23: a ticket entered `escalated` with its mandatory ≥10-char reason. The escalation SLA clock (Doc 12) starts here; the original assignee has auto-followed.',
        ],
        'ticket.deescalated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'previous_path', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/EscalateTicket'],
            'consumers' => ['Doc16/Notifications', 'Doc07/Inbox', 'Doc12/SlaAutomation', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-23: an escalated ticket returned to `open` with its de-escalation note. The current escalation payload cleared; history lives in the event stream.',
        ],
        'ticket.spam_marked' => [
            'schema_version' => 1,
            // Metric exclusion is NOT in this payload: N-14's predicate is
            // MetricExclusion reading status = spam — one place, no flag to desync.
            // `conversation_blocked` records whether the SEPARATE Doc 04 block was
            // also taken (its own conversation.blocked event still fires).
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'conversation_blocked', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/SpamTicket'],
            'consumers' => ['Doc15/Reporting', 'Doc07/Inbox', 'Doc17/Audit', 'Doc08/Rollups'],
            'description' => 'FR-09: a ticket was marked spam (reason on the audit entry, SEC-03). Excluded from SLA/CSAT/agent metrics while in this status (N-14); unmarkable for 7 days, terminal after (EC-09).',
        ],
        'ticket.spam_unmarked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'restored_status', 'actor_type', 'actor_id'],
            'producers' => ['Doc06/SpamTicket'],
            'consumers' => ['Doc15/Reporting', 'Doc07/Inbox', 'Doc17/Audit', 'Doc08/Rollups'],
            'description' => 'FR-09: a spam mark was reversed within its 7-day window — the prior status (new|open, A-10) restored from the event history, metrics re-included by the same one predicate that excluded them.',
        ],
        'ticket.bulk_completed' => [
            'schema_version' => 1,
            // Name per A-8(ii): FR-26's ticket.* family governs; FR-25's "tickets."
            // prefix is descriptive. `failed` is FR-25's failed[] — {ticket_id,
            // reason} per item, reasons from the stable C-04 code set. Per-item
            // progress is NOT an outbox event: it rides Reverb user.{requester}
            // (T-06), ephemeral by design.
            'payload' => ['tenant_id', 'bulk_job_id', 'action', 'ok', 'failed', 'requested_by'],
            'producers' => ['Doc06/TicketBulkJob'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-25: a bulk batch finished — per-item partial success, the summary carries ok count and failed[] with per-item stable reasons. SEC-03\'s bulk-summary audit entry references the same job row.',
        ],
        'ticket.escalation_rerouted' => [
            'schema_version' => 1,
            // A-15 (the A-8(iii) mechanism): EC-05 requires this event and FR-26's
            // list does not name it. `notify_user_id` is the ESCALATOR — recorded as
            // `by` in the escalation payload precisely so this event can name them.
            'payload' => ['tenant_id', 'ticket_id', 'from_target_user_id', 'notify_user_id', 'reason'],
            'producers' => ['Doc06/EscalateTicket'],
            'consumers' => ['Doc16/Notifications', 'Doc07/Inbox', 'Doc17/Audit'],
            'description' => 'EC-05: a named escalation target was deactivated after the fact — the escalation fell back to supervisor_review with a system note, and the escalator is to be notified.',
        ],

        // ── Doc 07 (Unified Inbox) — the S07 plan's enumeration: ONE new
        //    event (rule 16; view deltas and count pushes are realtime
        //    protocol v:1, NOT catalog events — the RealtimePusher line).
        'ai.decision.logged' => [
            'schema_version' => 1,
            // HIGH-VOLUME AND SAMPLED (FR-21; the Q7 payload-split law
            // generalized): identifiers + closed categories only — never
            // prompt/output text; full fidelity is the ai_decisions table.
            // The sample rate is the ai.decisions.event_sample_permille
            // platform setting.
            'payload' => ['tenant_id', 'decision_id', 'surface', 'task', 'action', 'error_category'],
            'producers' => ['Doc09/DecisionWriter'],
            'consumers' => ['Doc15/Reporting'],
            'description' => 'FR-20/FR-21: an AI task invocation was logged (SAMPLED to the bus — the table is the record; the stream is a signal).',
        ],
        'ai.handoff.created' => [
            'schema_version' => 1,
            // Q7: identifiers + the closed handoff reason category only.
            'payload' => ['tenant_id', 'conversation_id', 'ticket_id', 'decision_id', 'reason'],
            'producers' => ['Doc09/RgOverride', 'Doc09/ChatbotTurn'],
            'consumers' => ['Doc11/Routing', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-12/FR-13/FR-21: the AI handed a conversation to humans — the Doc 06 FR-11 packet is pinned on the ticket; decision_id is T-08\'s enrichment join key; Doc 11 routes (rg_risk -> the RG queue).',
        ],
        'ai.killswitch.changed' => [
            'schema_version' => 1,
            // 'all' stands in for a NULL scope axis so the closed payload stays
            // string-typed; the audit entry carries reason + old/new.
            'payload' => ['tenant_id', 'surface', 'channel', 'endpoint_ref', 'is_off', 'actor_id'],
            'producers' => ['Doc09/KillSwitches'],
            'consumers' => ['Doc07/HealthBanner', 'Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-18/FR-21/SEC-05: a kill switch flipped — exact blast radius (surface × channel × endpoint), instant, high-visibility; the inbox header banner mirrors it.',
        ],
        'ai.provider.health_changed' => [
            'schema_version' => 1,
            // RULE-16 AMENDMENT (filed + approved at the S09 step-8 stop; the
            // A-4/A-15 mechanism): T-01/FS-01 require degraded-mode events
            // FR-21's six do not name. Tenant-scoped by construction,
            // TRANSITION-ONLY, tri-state (the A-22 shape).
            'payload' => ['tenant_id', 'provider', 'status'],
            'producers' => ['Doc09/Orchestrator'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'T-01/FS-01: an AI provider\'s health transitioned for this tenant — healthy | degraded (a breaker tripped, the chain still has a hop) | down (all exhausted → bypass-to-handoff). The Doc 07 banner consumption is a separate future escalation.',
        ],
        'ai.resolved' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'conversation_id', 'decision_id'],
            'producers' => ['Doc09/ChatbotTurn'],
            'consumers' => ['Doc12/Csat', 'Doc15/Reporting'],
            'description' => 'FR-09/FR-21: the chatbot marked a conversation AI-resolved (no ticket); Doc 12 sends the 1-tap CSAT prompt; a customer reply within 24 h reopens the AI turn flagged post_resolution (EC-08: ×2 then mandatory handoff).',
        ],
        'ai.rg.flagged' => [
            'schema_version' => 1,
            // The excerpt and reason live on the insert-only rg_flag_history
            // row (Doc 08 T-05); the stream carries identifiers only.
            'payload' => ['tenant_id', 'customer_id', 'conversation_id', 'decision_id', 'history_id'],
            'producers' => ['Doc09/RgOverride'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-13/FR-21: the AI RG override fired — flag set through Doc 08\'s path (source=ai, mandatory excerpt), template sent, RG handoff created. FR-15\'s suppression contracts engage via customer.rg_flag.set, which the same path emits.',
        ],
        'ai.anomaly.detected' => [
            'schema_version' => 1,
            // FR-21 AMENDMENT 7->9 (countersigned at the S09 step-11 stop): one
            // event per anomaly alert; the numbers live on the supervisor
            // decision row (decision_id) — identifiers + categories only.
            'payload' => ['tenant_id', 'decision_id', 'kind', 'intent'],
            'producers' => ['Doc09/AnomalyScan'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-17/FR-21: the hourly anomaly scan raised an alert (kind volume|intent|sentiment; intent when kind=intent, else "all"). Doc 07 mints the notify.ai.anomaly bell rows for reports.supervisor.live holders; Doc 15 renders the feed.',
        ],
        'ai.digest.ready' => [
            'schema_version' => 1,
            // FR-21 AMENDMENT 7->9: one event per recipient digest; the
            // bullets live on the decision row.
            'payload' => ['tenant_id', 'decision_id', 'recipient_id'],
            'producers' => ['Doc09/DailyDigest'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting'],
            'description' => 'FR-17/FR-21: a manager\'s daily digest was written — Doc 07 mints exactly one notify.ai.digest bell row for recipient_id.',
        ],

        // ── Doc 10 (S10 step 4; the plan's six-event family — identifiers only, Q7) ──
        'kb.article.published' => [
            'schema_version' => 1,
            // FR-13: the publish event the durable index consumer rides (step 6);
            // ai_usable is the CATEGORY flag at publish time (FLAG 3's re-derivation).
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'version_id', 'language', 'audience', 'ai_usable'],
            'producers' => ['Doc10/Transitions'],
            'consumers' => ['Doc10/KbIndexMaintenance', 'Doc10/TranslationState', 'Doc10/KbSearchIndexer', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 10 FR-13: a variant was published — humans see it immediately; the index consumer chunks, sanitizes, embeds and swaps the retrieval generation; siblings recompute translation state (FR-03).',
        ],
        'kb.article.cited_by_ai' => [
            'schema_version' => 1,
            // P2 (S10 step 7b): one event per Doc 10 article the chatbot cited — the
            // version pin makes SS-04's forensics a join, not a search.
            'payload' => ['tenant_id', 'article_id', 'version_id', 'conversation_id', 'decision_id'],
            'producers' => ['Doc09/ChatbotTurn'],
            'consumers' => ['Doc10/Analytics', 'Doc15/Reporting'],
            'description' => 'Doc 10 FR-12: the chatbot cited this article version in this conversation (deflection attribution joins on it — the formula is Doc 15\'s).',
        ],
        'kb.article.inserted_by_agent' => [
            'schema_version' => 1,
            // P3 (S10 step 7b): the agent's ACT — emitted by the Doc 10 insert-link door
            // at the click, never on render (BR-01 untouched: inserting is not sending).
            'payload' => ['tenant_id', 'article_id', 'version_id', 'conversation_id', 'agent_id'],
            'producers' => ['Doc10/KbInsertLink'],
            'consumers' => ['Doc10/Analytics', 'Doc15/Reporting'],
            'description' => 'Doc 10 FR-12: an agent inserted this article as a link into the composer (Copilot FR-14).',
        ],
        // ── P4 (S10 step 7b): the A-44 amendment, GRANTED — kb.* 6 → 9. Identifiers only;
        //    each emitted inside its door's existing transaction; the Doc 07 projector's
        //    three arms deliver the registered notify.kb.* categories.
        'kb.variant.submitted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'actor_id'],
            'producers' => ['Doc10/Transitions'],
            'consumers' => ['Doc07/Inbox'],
            'description' => 'Doc 10 FR-07: a variant entered review — reviewers (kb.articles.publish holders, minus the actor) get notify.kb.review_requested.',
        ],
        'kb.variant.changes_requested' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'actor_id', 'comment_id'],
            'producers' => ['Doc10/Transitions'],
            'consumers' => ['Doc07/Inbox'],
            'description' => 'Doc 10 FR-07: a reviewer sent a variant back with a comment — the submitter (resolved from the audit trail) gets notify.kb.changes_requested.',
        ],
        'kb.lock.taken_over' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'variant_id', 'previous_holder_id', 'new_holder_id'],
            'producers' => ['Doc10/Locks'],
            'consumers' => ['Doc07/Inbox'],
            'description' => 'Doc 10 FR-05/T-07: an editing lock was taken over — the displaced holder gets notify.kb.lock_taken_over.',
        ],
        // ── Doc 10 FR-12 (S10 step 10): the two analytics events the plan's family names — identifiers + categories only (Q7).
        'kb.article.viewed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'version_id', 'language', 'surface', 'conversation_id'],
            'producers' => ['Doc10/UsageEvents'],
            'consumers' => ['Doc10/Analytics', 'Doc15/Reporting'],
            'description' => 'Doc 10 FR-12: a published article was rendered on a surface (widget | telegram | staff | copilot); conversation_id (nullable) is the deflection-attribution key Doc 15 joins on.',
        ],
        'kb.feedback.received' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'feedback_id', 'source', 'score', 'has_comment'],
            'producers' => ['Doc10/FeedbackWriter'],
            'consumers' => ['Doc10/Analytics', 'Doc15/Reporting'],
            'description' => 'Doc 10 FR-11/FR-12/EC-10: a deduplicated score landed (latest wins per visitor per article) — score is a category (-1|1), the comment never rides the bus.',
        ],
        // A-48 GRANTED IN PART (batch-C countersign, 2026-08-29): this event enters by filed amendment;
        // kb.article.deflection_attributed was REFUSED (deflection is Doc 15's metric — [D15]).
        'kb.feedback.anomaly' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'visitor_bucket', 'refused_count'],
            'producers' => ['Doc10/FeedbackWriter'],
            'consumers' => ['Doc15/Reporting'],
            'description' => 'Doc 10 EC-10/SEC-05: a visitor hit the feedback rate limit (feedback bombing signal for Doc 15). ONE event per visitor bucket per day — the flood the limiter refuses must never reappear on the bus; visitor_bucket is a truncated re-hash, never an identity; refused_count is the day\'s running total at emit time.',
        ],
        'kb.retrieval.updated' => [
            'schema_version' => 1,
            // FR-13: emitted after the ONE-statement generation swap (or a retire);
            // status names which — current | retired | failed.
            'payload' => ['tenant_id', 'article_id', 'variant_id', 'version_id', 'retrieval_generation', 'status'],
            'producers' => ['Doc10/KbIndexMaintenance'],
            'consumers' => ['Doc09/Retrieval', 'Doc10/KbSearchIndexer', 'Doc15/Reporting'],
            'description' => 'Doc 10 FR-13/T-05: the retrieval index for a variant changed generation (swap) or was retired — Doc 09 caches invalidate; Doc 15 counts index freshness.',
        ],
        'ai.config.published' => [
            'schema_version' => 1,
            // Q7 split: identifiers and categories only — payload CONTENT
            // lives on the immutable ai_config_versions row.
            'payload' => ['tenant_id', 'kind', 'key', 'version', 'config_version_id'],
            'producers' => ['Doc09/ConfigVersions'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-04/FR-21: an AI config version (persona, thresholds, guardrails, provider routing, prompt template, RG classifier) was published — instant, zero-deploy, with rollback as republish. SEC-05 high-visibility; the audit entry carries actor + old/new.',
        ],
        'agent.status_changed' => [
            'schema_version' => 1,
            // FR-28's routing contract: Online = routable; Away keeps
            // assignments, not routable; Offline = manual/logout. Identifiers
            // and state words only (Q7) — `since` and display attribution live
            // on the agent_statuses row; audit carries actor + old/new.
            'payload' => ['tenant_id', 'user_id', 'status', 'reason', 'set_by_user_id'],
            'producers' => ['Doc07/AgentStatus'],
            'consumers' => ['Doc11/Routing', 'Doc15/Reporting'],
            'description' => 'FR-28: an agent\'s availability changed (online|away|offline; reason manual|auto_idle|heartbeat_lost|forced|logout). Doc 11 consumes it for routability; Doc 15 counts transitions — active-time metrics derive from activity events, never from this state (BR-06).',
        ],

        // ── Doc 08 FR-22: the customer family — TWELVE events, the closed
        //    enumeration of record (S08 plan §1; grows only by filed amendment,
        //    standing rule 16). Identifiers only (Q7): reasons, field values and
        //    actor detail live in audit_log; a consumer re-fetches by id under
        //    its own tenant context. `customer.updated` covers PROFILE attribute
        //    writes (name/language/country/tags); `customer.fields_changed`
        //    covers CUSTOM-FIELD values (entity=customer, Doc 12 definitions —
        //    FR-16's betting fields ride the seeded set), a reading filed with
        //    the S08 plan.
        'customer.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'customer_id', 'created_source'],
            'producers' => ['Doc08/CustomerResolver', 'Doc08/Customers'],
            'consumers' => ['Doc08/SearchIndexer', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-22/FR-02: a customer came into existence — a resolver shell (created_source=telegram|webchat) or a staff/api creation. Shells are full customers (FR-02).',
        ],
        'customer.updated' => [
            'schema_version' => 1,
            // `changed` is the list of profile attribute NAMES that changed —
            // never values (Q7; EC-11's old/new pairs live in audit_log).
            'payload' => ['tenant_id', 'customer_id', 'changed'],
            'producers' => ['Doc08/CustomerProfile'],
            'consumers' => ['Doc08/SearchIndexer', 'Doc07/ListProjector', 'Doc17/Audit'],
            'description' => 'FR-22/FR-01: profile attributes changed (display_name, language, country, tags…). The name list rides the payload; values live in audit.',
        ],
        'customer.identity.linked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'customer_id', 'identity_id', 'type', 'source'],
            'producers' => ['Doc08/LinkIdentity', 'Doc08/CustomerResolver'],
            'consumers' => ['Doc08/SearchIndexer', 'Doc17/Audit'],
            'description' => 'FR-22/FR-09 (and FR-07\'s shell creation): an identity attached to a customer. type=telegram|web|phone|email; source=channel_auto|staff|api (FR-05).',
        ],
        'customer.identity.unlinked' => [
            'schema_version' => 1,
            // new_customer_id is the fresh shell that received the identity and
            // its conversations (FR-10: ownership follows the anchoring identity).
            'payload' => ['tenant_id', 'customer_id', 'identity_id', 'type', 'new_customer_id'],
            'producers' => ['Doc08/UnlinkIdentity'],
            'consumers' => ['Doc08/SearchIndexer', 'Doc07/ListProjector', 'Doc17/Audit'],
            'description' => 'FR-22/FR-10: an identity moved off a profile onto a fresh shell, carrying its conversations/tickets. High-risk, typed-confirmed, heavily audited.',
        ],
        'customer.merged' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'survivor_id', 'merged_id', 'snapshot_id'],
            'producers' => ['Doc08/CustomerMerge'],
            'consumers' => ['Doc07/ListProjector', 'Doc08/SearchIndexer', 'Doc08/Rollups', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'FR-22/FR-12 (T-03): a merge completed — merged became a tombstone, everything re-pointed to the survivor. Doc 07\'s projector rewrites list rows from it (EC-01\'s live re-point).',
        ],
        'customer.unmerged' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'survivor_id', 'restored_id', 'snapshot_id'],
            'producers' => ['Doc08/UnmergeCustomer'],
            'consumers' => ['Doc07/ListProjector', 'Doc08/SearchIndexer', 'Doc08/Rollups', 'Doc17/Audit'],
            'description' => 'FR-22/FR-13: a 30-day undo executed — pre-merge pointers restored byte-identically (AC-03); post-merge objects stayed with the survivor.',
        ],
        'customer.blocked' => [
            'schema_version' => 1,
            // The cascade consumer (T-06) needs only the customer id; the
            // mandatory reason lives in audit_log (SEC-02).
            'payload' => ['tenant_id', 'customer_id', 'blocked_by'],
            'producers' => ['Doc08/BlockCustomer'],
            'consumers' => ['Doc08/BlockingCascade', 'Doc07/ListProjector', 'Doc17/Audit'],
            'description' => 'FR-22/FR-18 (T-06): customer-level block — the cascade consumer quiets every conversation, and the outbound guard refuses new sends independently (belt and braces, FS-04).',
        ],
        'customer.unblocked' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'customer_id', 'unblocked_by'],
            'producers' => ['Doc08/BlockCustomer'],
            'consumers' => ['Doc08/BlockingCascade', 'Doc07/ListProjector', 'Doc17/Audit'],
            'description' => 'FR-22/FR-18: the distinct unblock action (rides customers.block per A-39) — conversation states restore; quarantined messages are not replayed.',
        ],
        'customer.rg_flag.set' => [
            'schema_version' => 1,
            // Identifiers only — BR-03/SEC-01 discipline extends to the stream:
            // the reason and excerpt live on the insert-only history row, read
            // through the permission-gated no-cache repository (T-05).
            'payload' => ['tenant_id', 'customer_id', 'history_id', 'source'],
            'producers' => ['Doc08/RgFlagService'],
            'consumers' => ['Doc11/Routing', 'Doc12/Automation', 'Doc14/SmsSuppression', 'Doc17/Audit'],
            'description' => 'FR-22/FR-14: the RG flag was set (source=staff|ai). FR-15\'s contracts consume it: Doc 11 routes new tickets to the RG queue, Doc 12 suppresses promotional automations, Doc 14 suppresses non-essential SMS.',
        ],
        'customer.rg_flag.cleared' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'customer_id', 'history_id'],
            'producers' => ['Doc08/RgFlagService'],
            'consumers' => ['Doc11/Routing', 'Doc12/Automation', 'Doc14/SmsSuppression', 'Doc09/Calibration', 'Doc17/Audit'],
            'description' => 'FR-22/FR-15: the flag was cleared (permission + mandatory reason; history retained forever). Doc 09\'s calibration consumes clear-reasons (EC-06) — via the history row, not this payload.',
        ],
        'customer.note.added' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'customer_id', 'note_id', 'author_id', 'pinned'],
            'producers' => ['Doc08/CustomerNotes'],
            'consumers' => ['Doc07/ListProjector', 'Doc17/Audit'],
            'description' => 'FR-22/FR-17: a customer note was added (body never rides the stream — N-04\'s cousin discipline; the pinned one surfaces in the Doc 07 panel header).',
        ],
        'customer.fields_changed' => [
            'schema_version' => 1,
            // `field_keys` are the custom-field definition keys that changed —
            // FR-16's betting fields ride the seeded set; values live in audit.
            'payload' => ['tenant_id', 'customer_id', 'field_keys'],
            'producers' => ['Doc08/CustomerProfile'],
            'consumers' => ['Doc08/SearchIndexer', 'Doc17/Audit'],
            'description' => 'FR-22/FR-16: custom-field values changed for entity=customer (Doc 12 definitions; the player_id change re-feeds the duplicate-hint counts and the search index).',
        ],

        /*
        | Doc 11 — FR-19 (S11 step 2b; SCHEMA AMENDMENT 5 countersigned; the family is CLOSED at
        | EIGHTEEN — the document's own FR-19 list; the plan's "sixteen" was a miscount, corrected
        | here on the record — and grows only by filed amendment). Payloads carry identifiers and integers only —
        | never names or record text (SEC-02/SEC-05). Every Doc 11 consumer is DURABLE (ADR 0011).
        */
        'org.department.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'department_id'],
            'producers' => ['Doc11/Departments'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19: a department was created (FR-01/02/03).',
        ],
        'org.department.updated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'department_id', 'changed'],
            'producers' => ['Doc11/Departments'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19: a department changed; `changed` lists the field names only.',
        ],
        'org.department.deleted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'department_id'],
            'producers' => ['Doc11/Departments'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19/FR-18: a department was tombstoned after the restructure wizard moved every reference.',
        ],
        'org.team.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'team_id'],
            'producers' => ['Doc11/Teams'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19: a team was created (FR-01/02/03).',
        ],
        'org.team.updated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'team_id', 'changed'],
            'producers' => ['Doc11/Teams'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19: a team changed; `changed` lists the field names only.',
        ],
        'org.team.deleted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'team_id'],
            'producers' => ['Doc11/Teams'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19/FR-18: a team was tombstoned after the restructure wizard moved every reference.',
        ],
        'org.queue.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id'],
            'producers' => ['Doc11/Queues'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19: a queue was created (FR-01/02/03).',
        ],
        'org.queue.updated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id', 'changed'],
            'producers' => ['Doc11/Queues'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19: a queue changed; `changed` lists the field names only.',
        ],
        'org.queue.deleted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id'],
            'producers' => ['Doc11/Queues'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-19/FR-18: a queue was tombstoned after the restructure wizard moved every reference — EC-06: the orphan path consumes it.',
        ],
        'queue.membership_changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id', 'user_id', 'change', 'via'],
            'producers' => ['Doc11/Queues'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => "Doc 11 FR-19/FR-04: an effective member joined or left (change = added|removed; via = direct|team|temporary) — an availability event for the dispatcher (T-03) and Doc 07 EC-09's queue-view vanish.",
        ],
        'queue.temporary_grant.created' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id', 'user_id', 'override_id', 'expires_at'],
            'producers' => ['Doc11/PullIn'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-15/T-07/SEC-03: a pull-in created its Doc 03 grant (override_id) and the temporary membership.',
        ],
        'queue.temporary_grant.expired' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id', 'user_id', 'override_id', 'cause'],
            'producers' => ['Doc11/ExpirySweep'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc17/Audit'],
            'description' => 'Doc 11 T-07/EC-07: a temporary membership ended (cause = expired|ended_by_staff); assignments already made persist.',
        ],
        'ticket.routed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'record_id', 'queue_id', 'assigned_to'],
            'producers' => ['Doc11/Routing'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-17/FR-19: an automatic routing decision was recorded (record_id → routing_decisions); assigned_to null = waiting. The timeline expandable reads the record by id (SEC-02 elision at the read door).',
        ],
        'ticket.waiting' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'queue', 'position'],
            'producers' => ['Doc11/Routing'],
            'consumers' => ['Doc07/Inbox', 'Doc09/Chatbot', 'Doc15/Reporting'],
            'description' => 'Doc 11 FR-19/FR-08 step 5: the ticket waits in `queue` at integer `position` (null = placed, not yet ranked); the estimator recomputes on it (T-06).',
        ],
        'routing.starvation_boosted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'queue_id', 'waited_seconds', 'cap_minutes'],
            'producers' => ['Doc11/Routing'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-12/NFR-06/SEC-06: the age cap promoted a ticket to the queue head — standard audit.',
        ],
        'routing.no_skill_match' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'queue_id', 'required_language', 'waiting_count'],
            'producers' => ['Doc11/Routing'],
            'consumers' => ['Doc15/Reporting', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-11/EC-04: a wait-fallback skill queue held past the alert threshold with no matching agent online (counts only, never names — SEC-05).',
        ],
        'rescue.flagged' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'reason'],
            'producers' => ['Doc11/Rescue'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'Doc 11 FR-16: a ticket entered the Rescue view (reason = heartbeat_lost|channel_unreachable|orphaned).',
        ],
        // ── Doc 12 (Automation, SLA, Forms & Custom Fields) — the S12
        //    EVENT-FAMILY AMENDMENT (ANSWERS, 28a2e67); events land per step,
        //    each citing the filing. NFR-06: zero configuration change is silent.
        'automation.rule.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'rule_id', 'revision_id', 'kind'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-07: a rule changed (kind: created|updated|activated|deactivated). Activation swaps the revision pointer atomically; activated/deactivated are the SEC-06 high-risk pair.',
        ],
        'automation.rule.executed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'rule_id', 'revision_id', 'event_id', 'ticket_id', 'matched'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-06: a rule MATCHED an event and its action chain dispatched. The full per-evaluation detail (condition truth, action outcomes) is the executions table; Doc 15 counts these.',
        ],
        'automation.loop_guard.tripped' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'rule_id', 'ticket_id', 'guard'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc15/Reporting', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-04/T-03: a guard cap stopped evaluation (guard: max_rules|max_actions|chain_depth) — the alert arm; rule_id null on a depth skip.',
        ],
        // S12 step 13 — EVENT-FAMILY AMENDMENT 2 (filed before these entries): the external arms' products.
        'automation.webhook.disabled' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'destination_id', 'rule_id', 'delivery_id', 'attempts'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc15/Reporting', 'Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'Doc 12 EC-13: a webhook destination exhausted retry ×8 and AUTO-DISABLED — the alert arm (rule_id nullable; delivery_id = the exhausting delivery).',
        ],
        'automation.notify.requested' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'rule_id', 'execution_id', 'ticket_id', 'category', 'user_ids'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc16/Notifications', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-03 notify: resolved recipient user ids + a REGISTERED category — identifiers only; Doc 16 delivers ([D16]). ticket_id nullable.',
        ],
        'automation.sms.requested' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'rule_id', 'execution_id', 'ticket_id', 'customer_id', 'template_ref'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc14/Sms', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-03 send SMS template (BR-04): a TEMPLATE REFERENCE and identifiers — NO text key exists on this payload; Doc 14 renders and applies its guards ([D14]).',
        ],
        'customer.suppression.set' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'customer_id', 'rule_id', 'reason'],
            'producers' => ['Doc12/Automation'],
            'consumers' => ['Doc08/Customers', 'Doc14/Sms', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-03 suppress: the marketing-suppression flag set on a customer (Doc 08 FR-15 consumers read it via MarketingSuppression::isSuppressed); rule_id nullable for a staff set.',
        ],
        'sla.policy.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'policy_id', 'kind'],
            'producers' => ['Doc12/Sla'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-08: an SLA policy changed (kind: created|updated). Prospective-only — armed instances keep their snapshots (BR-03).',
        ],
        'sla.instance.armed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'instance_id', 'policy_id'],
            'producers' => ['Doc12/Sla'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-09: the first matching policy armed an instance on ticket creation (snapshots embedded; one per ticket).',
        ],
        'sla.warned' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'instance_id', 'stage', 'due_at', 'late_fired'],
            'producers' => ['Doc12/Sla'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc12/Automation', 'Doc15/Reporting'],
            'description' => 'Doc 12 FR-10: a stage crossed its warning threshold. due_at is the COMPUTED instant (RIDER 1: events carry the true timeline; the row carries detection time); late_fired flags a recovery fire (FS-02).',
        ],
        'sla.breached' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'instance_id', 'stage', 'due_at', 'late_fired'],
            'producers' => ['Doc12/Sla'],
            'consumers' => ['Doc07/Inbox', 'Doc11/Routing', 'Doc12/Automation', 'Doc15/Reporting'],
            'description' => 'Doc 12 FR-10: a stage reached 100% of its target. Same honesty shape as sla.warned; the FR-08 breach-escalation hook consumes it.',
        ],
        'field.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'field_id', 'kind'],
            'producers' => ['Doc12/Fields'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-17/FR-18: a field definition changed (kind: created|updated|deactivated|reactivated|option_changed). Keys never reuse; types never change — grant physics.',
        ],
        'macro.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'macro_id', 'kind'],
            'producers' => ['Doc12/Macros'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-14: a macro or saved reply changed (kind: created|updated). Retirement is active=false — rows never delete.',
        ],
        'macro.used' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'macro_id', 'ticket_id'],
            'producers' => ['Doc12/Macros'],
            'consumers' => ['Doc15/Reporting'],
            'description' => 'Doc 12 FR-16: a macro was inserted (usage drives palette ranking here and the Doc 15 macro report there).',
        ],
        'csat.sent' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'survey_id', 'ticket_id', 'customer_id', 'resolver_type'],
            'producers' => ['Doc12/Csat'],
            'consumers' => ['Doc15/Reporting'],
            'description' => 'Doc 12 FR-24: a survey armed and its 1-tap prompt sent (one per resolution event; cooldown- and RG-suppressed arming never emits). ticket_id is null for a conversation-anchored AI resolution (RIDER 4).',
        ],
        'csat.received' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'survey_id', 'ticket_id', 'customer_id', 'score', 'comment', 'resolver_type'],
            'producers' => ['Doc12/Csat'],
            'consumers' => ['Doc15/Reporting', 'Doc12/Automation', 'Doc09/OutcomeEnrichment'],
            'description' => 'Doc 12 FR-25: a response landed (or an in-window edit replaced one — same shape, C-09 idempotent consumers). Doc 15\'s CSAT source of truth; the FR-01 trigger; score ≤2 raised the FR-20 review item.',
        ],
        'csat.expired' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'survey_id', 'ticket_id'],
            'producers' => ['Doc12/Csat'],
            'consumers' => ['Doc15/Reporting'],
            'description' => 'Doc 12 EC-11: the 72 h window closed with no response — non-response is data, recorded honestly.',
        ],
        'form.changed' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'form_id', 'version_id', 'kind'],
            'producers' => ['Doc12/Forms'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-20/FR-22: a form changed (kind: created|updated|published). version_id is set exactly when kind=published — BR-06\'s immutable version was minted.',
        ],
        'form.submitted' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'form_id', 'version_id', 'ticket_id', 'channel', 'complete'],
            'producers' => ['Doc12/Forms', 'Doc05/Flows'],
            'consumers' => ['Doc12/Automation', 'Doc09/Handoff', 'Doc15/Reporting'],
            'description' => 'Doc 12 FR-22 / SS-03: a submission snapshotted {version_id + answers} onto its ticket. complete=false is the mid-form handoff (Doc 09 FR-12 partial form_data). The FR-01 trigger.',
        ],
        'hours.calendar.updated' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'calendar_id', 'kind'],
            'producers' => ['Doc12/Hours'],
            'consumers' => ['Doc15/Reporting', 'Doc17/Audit'],
            'description' => 'Doc 12 FR-12: a business calendar changed (kind: created|updated|holiday_added|holiday_updated|holiday_removed|deactivated). Prospective-only — armed SLA instances keep their snapshots (BR-03/EC-05).',
        ],
        'rescue.auto_returned' => [
            'schema_version' => 1,
            'payload' => ['tenant_id', 'ticket_id', 'conversation_id', 'queue_id', 'previous_assignee_id'],
            'producers' => ['Doc11/Rescue'],
            'consumers' => ['Doc07/Inbox', 'Doc15/Reporting', 'Doc17/Audit'],
            'description' => "Doc 11 FR-16a: routing.rescue.auto returned a heartbeat-lost agent's ticket to its queue with a system note.",
        ],
    ];

    /**
     * @return array{schema_version: int, payload: list<string>, producers: list<string>, consumers: list<string>, description: string}
     */
    public static function definition(string $name): array
    {
        if (! array_key_exists($name, self::EVENTS)) {
            throw new InvalidArgumentException(sprintf(
                'Event "%s" is not in the event catalog (Doc 01 §21). Add it to '
                .'App\Support\Events\EventCatalog::EVENTS with its payload keys, producers and '
                .'consumers, then run `php ci/event-catalog.php --write` to regenerate '
                .'docs/EVENT-CATALOG.md. An undocumented event is an integration contract nobody '
                .'downstream can see.',
                $name,
            ));
        }

        return self::EVENTS[$name];
    }

    /**
     * Reject a payload that does not match the declared shape exactly.
     *
     * BOTH directions matter. A missing key breaks consumers. An EXTRA key is how
     * PII leaks into the event stream: someone adds `customer_phone` "just for
     * convenience", it lands in `events` for 13 months (NFR-07) and in every
     * subscriber's logs, and SEC-07 is quietly gone. Neither is permitted.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertPayloadMatches(string $name, array $payload): void
    {
        $declared = self::definition($name)['payload'];
        $actual = array_keys($payload);

        sort($declared);
        sort($actual);

        if ($declared === $actual) {
            return;
        }

        $missing = array_values(array_diff($declared, $actual));
        $extra = array_values(array_diff($actual, $declared));

        throw new InvalidArgumentException(sprintf(
            'Event "%s" payload does not match its catalog entry.%s%s Declared: [%s]. '
            .'Extra keys are refused as firmly as missing ones — SEC-07 keeps PII out of the event '
            .'stream by declaring what each event may carry.',
            $name,
            $missing === [] ? '' : sprintf(' Missing: [%s].', implode(', ', $missing)),
            $extra === [] ? '' : sprintf(' Not declared: [%s].', implode(', ', $extra)),
            implode(', ', $declared),
        ));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::EVENTS);
    }
}
